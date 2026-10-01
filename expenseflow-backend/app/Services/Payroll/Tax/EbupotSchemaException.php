<?php

namespace App\Services\Payroll\Tax;

use RuntimeException;

/**
 * Dokumen e-Bupot gagal validasi XSD — ekspor dibatalkan (Fase 6).
 *
 * Dilempar oleh EbupotXmlBuilder sebelum berkas dikembalikan, sehingga berkas
 * yang tidak sesuai skema TIDAK PERNAH sampai ke pengguna. Pemanggil (controller)
 * menerjemahkannya menjadi HTTP 422 berisi daftar galat.
 */
class EbupotSchemaException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors  pesan galat libxml (sudah dibatasi jumlahnya)
     */
    public function __construct(
        private readonly array $errors,
        private readonly string $schemaPath,
        private readonly bool $official,
    ) {
        parent::__construct('Dokumen e-Bupot tidak lolos validasi skema XSD.');
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function schemaPath(): string
    {
        return $this->schemaPath;
    }

    /** True bila yang menolak adalah skema RESMI DJP (bukan skema internal). */
    public function isOfficial(): bool
    {
        return $this->official;
    }
}
