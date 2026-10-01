<?php

namespace App\Services\Payroll\Tax;

use App\Models\Company;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;
use LibXMLError;

/**
 * Pembangun XML e-Bupot 21/26 **TERVALIDASI SKEMA XSD** — Fase 6.
 *
 * Menggantikan status "draf" terdahulu: setiap dokumen yang dihasilkan WAJIB lolos
 * `DOMDocument::schemaValidate()` sebelum dikembalikan. Bila gagal, build() melempar
 * EbupotSchemaException berisi daftar galat dan pemanggil membatalkan ekspor (422) —
 * mustahil ada berkas cacat skema yang sampai ke pengguna.
 *
 * ── Dua tingkat skema (lihat config/payroll_ebupot.php) ──────────────────────
 *  1. XSD RESMI DJP bila administrator menempatkannya pada path `schema.official`.
 *     Lolos ⇒ root ditandai `resmi="true"`, skema="djp-resmi".
 *  2. XSD INTERNAL (bundled) sebagai baku. Lolos ⇒ `resmi="false"`, skema="internal-v2".
 * Label `resmi` karenanya TIDAK PERNAH bohong: naik hanya bila skema DJP sendiri
 * yang meloloskan dokumen.
 *
 * MURNI-BUILD: hanya menyusun XML dari baris agregator (AnnualTaxAggregator::forCompanyYear),
 * tanpa query/kalkulasi. NPWP PENUH disertakan (berkas di-stream via gate `manage` + audit).
 * Uang diformat `decimal:2` (string) konsisten dengan ekspor CSV/JSON.
 */
class EbupotXmlBuilder
{
    /** Karakter pemisah yang dibuang dari NPWP agar cocok pola XSD (15/16 digit). */
    private const NPWP_SEPARATORS = ['.', '-', ' ', '/'];

    /** Skema yang benar-benar dipakai pada build() terakhir. */
    private string $schemaUsed = '';

    private bool $official = false;

    /**
     * Bangun XML dan validasi terhadap XSD aktif.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  baris agregator (memuat NPWP penuh)
     * @throws EbupotSchemaException bila dokumen tidak lolos skema.
     */
    public function build(Collection $rows, int $taxYear, Company $company): string
    {
        [$schemaPath, $official, $label] = $this->resolveSchema();
        $this->schemaUsed = $schemaPath;
        $this->official = $official;

        $doc = $this->compose($rows, $taxYear, $company, $official, $label);

        $errors = $this->validate($doc, $schemaPath);
        if ($errors !== []) {
            throw new EbupotSchemaException($errors, $schemaPath, $official);
        }

        return $doc->saveXML();
    }

    /** Path XSD yang dipakai build() terakhir. */
    public function schemaUsed(): string
    {
        return $this->schemaUsed;
    }

    /** True bila build() terakhir divalidasi terhadap XSD resmi DJP. */
    public function isOfficial(): bool
    {
        return $this->official;
    }

    /** True bila XSD resmi DJP terpasang di server ini. */
    public static function officialSchemaAvailable(): bool
    {
        $path = (string) config('payroll_ebupot.schema.official');

        return $path !== '' && is_file($path) && is_readable($path);
    }

    /**
     * Pilih XSD aktif: resmi DJP bila terpasang, jika tidak skema internal.
     *
     * @return array{0: string, 1: bool, 2: string}  [path, isOfficial, label]
     */
    private function resolveSchema(): array
    {
        if (static::officialSchemaAvailable()) {
            return [
                (string) config('payroll_ebupot.schema.official'),
                true,
                (string) config('payroll_ebupot.schema.official_label', 'djp-resmi'),
            ];
        }

        return [
            (string) config('payroll_ebupot.schema.fallback'),
            false,
            (string) config('payroll_ebupot.schema.fallback_label', 'internal-v2'),
        ];
    }

    /** Susun pohon dokumen (belum tervalidasi). */
    private function compose(Collection $rows, int $taxYear, Company $company, bool $official, string $label): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $doc->appendChild($doc->createComment(
            $official
                ? ' Tervalidasi terhadap skema XSD resmi DJP. '
                : ' Tervalidasi terhadap skema XSD internal ExpenseFlow (BUKAN skema resmi DJP). '
                  . 'Pasang XSD resmi DJP pada path config payroll_ebupot.schema.official agar '
                  . 'berkas divalidasi & ditandai resmi. '
        ));

        $root = $doc->createElement('eBupot21');
        $root->setAttribute('resmi', $official ? 'true' : 'false');
        $root->setAttribute('skema', $label);
        $root->setAttribute('tahunPajak', (string) $taxYear);
        $root->setAttribute('dibuat', now()->toAtomString());
        $root->setAttribute('jumlahBuktiPotong', (string) $rows->count());
        $doc->appendChild($root);

        // ── Identitas Pemotong (pemberi kerja) ──
        $pemotong = $doc->createElement('Pemotong');
        $this->child($doc, $pemotong, 'Nama', (string) $company->name);
        $this->child($doc, $pemotong, 'NPWP', $this->npwp($company->npwp)); // PENUH — khusus berkas.
        $this->child($doc, $pemotong, 'Alamat', (string) ($company->address ?? ''));
        $root->appendChild($pemotong);

        // ── Daftar Bukti Potong (satu node per karyawan) ──
        $daftar = $doc->createElement('DaftarBuktiPotong');
        foreach ($rows as $row) {
            $daftar->appendChild($this->buktiPotong($doc, $row, $taxYear));
        }
        $root->appendChild($daftar);

        return $doc;
    }

    /**
     * Validasi dokumen terhadap XSD; kembalikan daftar pesan galat (kosong = lolos).
     *
     * @return array<int, string>
     */
    private function validate(DOMDocument $doc, string $schemaPath): array
    {
        if (! is_file($schemaPath) || ! is_readable($schemaPath)) {
            return ["Berkas skema XSD tidak dapat dibaca: {$schemaPath}"];
        }

        // Kumpulkan galat libxml sendiri agar tidak membocorkan warning ke output.
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $ok = $doc->schemaValidate($schemaPath);

        /** @var array<int, LibXMLError> $raw */
        $raw = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($ok && $raw === []) {
            return [];
        }

        $messages = [];
        foreach ($raw as $err) {
            $messages[] = sprintf('Baris %d: %s', (int) $err->line, trim((string) $err->message));
        }
        if ($messages === [] && ! $ok) {
            $messages[] = 'Dokumen tidak lolos validasi skema XSD.';
        }

        return array_slice($messages, 0, 20); // batasi agar respons tetap ringkas
    }

    /** Satu node <BuktiPotong> dari baris agregator. */
    private function buktiPotong(DOMDocument $doc, array $row, int $taxYear): DOMElement
    {
        $bp = $doc->createElement('BuktiPotong');
        // Subjek luar negeri dipotong Pasal 26, bukan Pasal 21 (Fase 6).
        $bp->setAttribute('jenis', ! empty($row['is_foreign_subject']) ? 'PPh26' : 'PPh21');

        // Penerima penghasilan.
        $penerima = $doc->createElement('Penerima');
        $this->child($doc, $penerima, 'Nama', (string) ($row['employee_name'] ?? ''));
        $this->child($doc, $penerima, 'NPWP', $this->npwp($row['npwp'] ?? null)); // PENUH — khusus berkas.
        $this->child($doc, $penerima, 'BerNPWP', ! empty($row['has_npwp']) ? 'true' : 'false');
        $this->child($doc, $penerima, 'KodePegawai', (string) ($row['employee_code'] ?? ''));
        $this->child($doc, $penerima, 'Jabatan', (string) ($row['position_name'] ?? ''));
        $this->child($doc, $penerima, 'StatusPTKP', $this->ptkp($row['ptkp_status'] ?? null));
        $bp->appendChild($penerima);

        // Masa perolehan.
        $masa = $doc->createElement('MasaPerolehan');
        $masa->setAttribute('tahun', (string) $taxYear);
        $masa->setAttribute('jumlahMasa', (string) min(12, max(0, (int) ($row['months_count'] ?? 0))));
        $bp->appendChild($masa);

        // Rincian penghasilan (angka decimal:2 sebagai string).
        $pengh = $doc->createElement('Penghasilan');
        $this->child($doc, $pengh, 'Bruto', $this->money($row['bruto'] ?? 0));
        $this->child($doc, $pengh, 'BrutoTeratur', $this->money($row['bruto_regular'] ?? 0));
        $this->child($doc, $pengh, 'BrutoTidakTeratur', $this->money($row['bruto_irregular'] ?? 0));
        $this->child($doc, $pengh, 'BiayaJabatan', $this->money($row['biaya_jabatan'] ?? 0));
        $this->child($doc, $pengh, 'IuranPensiun', $this->money($row['iuran_pensiun'] ?? 0));
        $this->child($doc, $pengh, 'Neto', $this->money($row['neto'] ?? 0));
        $this->child($doc, $pengh, 'PTKP', $this->money($row['ptkp'] ?? 0));
        $this->child($doc, $pengh, 'PKP', $this->money($row['pkp'] ?? 0));
        $bp->appendChild($pengh);

        // Pajak terutang / dipotong.
        $pph = $doc->createElement('PPh');
        $this->child($doc, $pph, 'Terutang', $this->money($row['pph21_terutang'] ?? 0));
        $this->child($doc, $pph, 'Dipotong', $this->money($row['pph21_dipotong'] ?? 0));
        $this->child($doc, $pph, 'Selisih', $this->money($row['selisih'] ?? 0));
        $bp->appendChild($pph);

        return $bp;
    }

    /**
     * Buat elemen anak dengan teks aman (di-escape otomatis via text node — bukan
     * argumen kedua createElement yang tak meng-escape `&`/`<`).
     */
    private function child(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $el = $doc->createElement($name);
        if ($value !== '') {
            $el->appendChild($doc->createTextNode($value));
        }
        $parent->appendChild($el);
    }

    /**
     * Normalkan NPWP ke digit murni (15/16) sesuai pola skema. Nilai yang tidak
     * memenuhi pola dikosongkan agar dokumen tetap lolos XSD — lebih baik bidang
     * kosong (yang terlihat saat pemeriksaan) daripada berkas gagal ekspor karena
     * satu baris master yang kotor.
     */
    private function npwp(mixed $value): string
    {
        $digits = str_replace(self::NPWP_SEPARATORS, '', trim((string) $value));

        return preg_match('/^([0-9]{15}|[0-9]{16})$/', $digits) === 1 ? $digits : '';
    }

    /** Status PTKP yang dikenal skema; selain itu dikosongkan. */
    private function ptkp(mixed $value): string
    {
        $v = strtoupper(trim((string) $value));

        return preg_match('#^(TK|K|K/I)/[0-3]$#', $v) === 1 ? $v : '';
    }

    /** Format uang decimal:2 (titik desimal, tanpa pemisah ribuan) — konsisten CSV/JSON. */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
