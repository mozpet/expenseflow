<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CurrencyRate;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master Kurs Valuta Asing (effective-dated) — Penggajian Multi-Mata Uang, Fase 6.
 *
 * Tiap baris = kurs 1 unit mata uang terhadap Rupiah yang berlaku SEJAK tanggal
 * tertentu (mis. Kurs Menteri Keuangan mingguan). Kalkulator payroll memilih
 * baris dengan `effective_date` terbesar yang ≤ tanggal periode; baris milik
 * perusahaan mengalahkan baris global (company_id NULL) — pola yang sama dengan
 * `statutory_rule_versions`.
 *
 * KEPUTUSAN PENTING: bila kurs tidak ditemukan, kalkulasi GAGAL (422) — sistem
 * TIDAK PERNAH mengasumsikan 1:1, karena itu akan menghasilkan slip yang salah
 * secara senyap.
 *
 * Kurs yang sudah dipakai batch terkunci pada `payrolls.exchange_rates`, jadi
 * memperbarui master di sini tidak mengubah slip yang sudah dihitung.
 */
class CurrencyRateController extends Controller
{
    private function deny(): JsonResponse
    {
        return response()->json(['message' => 'Akses ditolak. Anda tidak memiliki wewenang mengelola kurs.'], 403);
    }

    /** GET /dashboard/payroll/currency-rates */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'read')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;

        // Kurs global (NULL) ikut ditampilkan sebagai acuan, namun hanya baris
        // milik perusahaan yang dapat diubah/dihapus lewat endpoint ini.
        $rates = CurrencyRate::query()
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
            ->when($request->filled('currency'), fn ($q) => $q->where('currency', strtoupper((string) $request->input('currency'))))
            ->orderBy('currency')
            ->orderByDesc('effective_date')
            ->limit(500)
            ->get()
            ->map(function (CurrencyRate $rate) use ($companyId) {
                $arr = $rate->toArray();
                $arr['is_editable'] = (int) $rate->company_id === $companyId;
                $arr['scope'] = $rate->company_id === null ? 'global' : 'company';

                return $arr;
            });

        return response()->json(['data' => $rates]);
    }

    /** POST /dashboard/payroll/currency-rates */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }

        $companyId = (int) $user->company_id;

        $validated = $request->validate([
            'currency'       => ['required', 'string', 'size:3', 'alpha'],
            'rate_to_idr'    => 'required|numeric|min:0.000001|max:999999999',
            'effective_date' => 'required|date',
            'source'         => 'nullable|string|max:60',
        ]);

        $currency = strtoupper($validated['currency']);
        if ($currency === CurrencyRate::BASE_CURRENCY) {
            return response()->json([
                'message' => 'IDR adalah mata uang basis — kursnya selalu 1 dan tidak perlu diisi.',
                'errors'  => ['currency' => ['IDR adalah mata uang basis.']],
            ], 422);
        }

        $exists = CurrencyRate::where('company_id', $companyId)
            ->where('currency', $currency)
            ->whereDate('effective_date', $validated['effective_date'])
            ->exists();
        if ($exists) {
            return response()->json([
                'message' => 'Kurs untuk mata uang & tanggal berlaku tersebut sudah ada.',
            ], 422);
        }

        $rate = CurrencyRate::create([
            'company_id'     => $companyId,
            'currency'       => $currency,
            'rate_to_idr'    => round((float) $validated['rate_to_idr'], 6),
            'effective_date' => $validated['effective_date'],
            'source'         => $validated['source'] ?? null,
        ]);

        AuditLogger::log(
            action: 'CURRENCY_RATE_CREATED',
            description: "Menambah kurs {$currency} = {$rate->rate_to_idr} IDR berlaku {$validated['effective_date']}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'CurrencyRate',
            entityId: $rate->id,
            newValues: $rate->toArray(),
        );

        return response()->json(['message' => 'Kurs berhasil ditambahkan.', 'data' => $rate], 201);
    }

    /** PUT /dashboard/payroll/currency-rates/{currencyRate} */
    public function update(Request $request, CurrencyRate $currencyRate): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        // Baris global tidak dapat diubah dari dashboard perusahaan.
        if ((int) $currencyRate->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Kurs tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'rate_to_idr' => 'required|numeric|min:0.000001|max:999999999',
            'source'      => 'nullable|string|max:60',
        ]);

        $old = $currencyRate->toArray();
        $currencyRate->update([
            'rate_to_idr' => round((float) $validated['rate_to_idr'], 6),
            'source'      => $validated['source'] ?? $currencyRate->source,
        ]);

        AuditLogger::log(
            action: 'CURRENCY_RATE_UPDATED',
            description: "Memperbarui kurs {$currencyRate->currency} berlaku {$currencyRate->effective_date}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_INFO,
            entityType: 'CurrencyRate',
            entityId: $currencyRate->id,
            oldValues: $old,
            newValues: $currencyRate->toArray(),
        );

        return response()->json(['message' => 'Kurs berhasil diperbarui.', 'data' => $currencyRate]);
    }

    /** DELETE /dashboard/payroll/currency-rates/{currencyRate} */
    public function destroy(Request $request, CurrencyRate $currencyRate): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission(Role::MODULE_PAYROLL, 'manage')) {
            return $this->deny();
        }
        if ((int) $currencyRate->company_id !== (int) $user->company_id) {
            return response()->json(['message' => 'Kurs tidak ditemukan.'], 404);
        }

        $label = "{$currencyRate->currency} @ {$currencyRate->effective_date}";
        $id = $currencyRate->id;
        $currencyRate->delete();

        AuditLogger::log(
            action: 'CURRENCY_RATE_DELETED',
            description: "Menghapus kurs {$label}",
            category: AuditLogger::CATEGORY_FINANCE,
            severity: AuditLogger::SEVERITY_WARNING,
            entityType: 'CurrencyRate',
            entityId: $id,
        );

        return response()->json(['message' => "Kurs {$label} berhasil dihapus."]);
    }
}
