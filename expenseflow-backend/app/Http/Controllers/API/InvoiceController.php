<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceApproval;
use App\Models\InvoiceItem;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    // ─── Helper: catat aktivitas ──────────────────────────────
    private function logActivity(int $userId, int $companyId, string $action, string $description, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('activity_logs')->insert([
            'company_id'   => $companyId,
            'user_id'      => $userId,
            'action'       => $action,
            'description'  => $description,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // ─── Helper: kirim notifikasi ───────────────────────────────
    private function notifyUser(int $userId, string $type, array $data, ?string $entityType = null, ?int $entityId = null): void
    {
        DB::table('notifications')->insert([
            'id'              => \Illuminate\Support\Str::uuid()->toString(),
            'type'            => $type,
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id'   => $userId,
            'user_id'         => $userId,
            'data'            => json_encode($data),
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ─── Helper: mapping approval level ke roles yang diizinkan ──
    private function allowedRolesForLevel(int $currentLevel): array
    {
        return match ($currentLevel) {
            0 => ['finance', 'hrd', 'admin', 'super_admin'],   // Level 1: Finance Manager
            1 => ['admin', 'super_admin'],                       // Level 2: + Direksi
            2 => ['super_admin'],                                  // Level 3: + Komisaris
            default => [],
        };
    }

    // ─── Helper: label untuk setiap level ────────────────────────
    private function levelLabel(int $level): string
    {
        return match ($level) {
            0 => 'Finance Manager',
            1 => 'Direksi',
            2 => 'Komisaris',
            default => 'Unknown',
        };
    }

    // ═══════════════════════════════════════════════════════════
    // index() — GET list invoice milik company, filter status opsional
    //    GET /api/v1/dashboard/invoices?status=pending|approved|rejected
    //    Tanpa ?status= → tampilkan semua. Selalu sertakan ringkasan jumlah.
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $status    = $request->query('status');

        $validStatuses = ['pending', 'approved', 'rejected'];

        $query = Invoice::where('company_id', $companyId)
            ->with([
                'vendor:id,name,tax_id',
                'user:id,name',
                // Daftar approval dipakai frontend untuk separation of duties
                // (cek apakah user yang login sudah menyetujui invoice ini).
                'approvals:id,invoice_id,user_id,status,approval_level',
            ])
            ->withCount('items');

        if ($status && in_array($status, $validStatuses)) {
            $query->where('status', $status);
        }

        $invoices = $query->latest()->paginate(20);

        // Ringkasan jumlah per status (untuk badge/summary di dashboard)
        $summary = Invoice::where('company_id', $companyId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'summary' => [
                'pending'  => $summary['pending'] ?? 0,
                'approved' => $summary['approved'] ?? 0,
                'rejected' => $summary['rejected'] ?? 0,
            ],
            'invoices' => $invoices,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // show() — GET detail satu invoice lengkap (items + approvals + vendor)
    // ═══════════════════════════════════════════════════════════
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        // Cek ownership: invoice harus milik company yang sama
        if ($invoice->company_id !== $request->user()->company_id) {
            return response()->json(['message' => 'Invoice tidak ditemukan di perusahaan Anda.'], 403);
        }

        $invoice->load([
            'vendor',
            'user:id,name,role',
            'items',
            'approvals.user:id,name,role',
        ]);

        $levelLabels = [1 => 'Finance Manager', 2 => '+ Direksi', 3 => '+ Komisaris'];

        return response()->json([
            'invoice' => [
                'id'                     => $invoice->id,
                'invoice_number'         => $invoice->invoice_number,
                'po_number'              => $invoice->po_number,
                'vendor_id'              => $invoice->vendor_id,
                'vendor'                 => $invoice->vendor,
                'vendor_name'            => $invoice->vendor?->name,
                'user'                   => $invoice->user,
                'subtotal'               => $invoice->subtotal,
                'tax_amount'             => $invoice->tax_amount,
                'ppn_amount'             => $invoice->tax_amount,
                'discount_amount'        => $invoice->discount_amount,
                'total_amount'           => $invoice->total_amount,
                'invoice_date'           => $invoice->invoice_date,
                'due_date'               => $invoice->due_date,
                'currency'               => $invoice->currency,
                'status'                 => $invoice->status,
                'source'                 => $invoice->source,
                'category'               => $invoice->category,
                'notes'                  => $invoice->notes,
                'max_approval_level'     => $invoice->max_approval_level,
                'max_approval_label'     => $levelLabels[$invoice->max_approval_level] ?? null,
                'current_approval_level' => $invoice->current_approval_level,
                'items'                  => $invoice->items,
                'approvals'              => $invoice->approvals,
                'created_at'             => $invoice->created_at,
                'updated_at'             => $invoice->updated_at,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // store() — POST input invoice manual
    // ═══════════════════════════════════════════════════════════
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor_id'      => ['required', Rule::exists('vendors', 'id')],
            'invoice_number' => 'required|string|max:255|unique:invoices,invoice_number',
            'invoice_date'   => 'required|date',
            'due_date'       => 'required|date|after_or_equal:invoice_date',
            'category'       => 'required|string|max:100',
            'po_number'      => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:1000',
            'items'          => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity'    => 'required|numeric|min:0.01',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        $user      = $request->user();
        $companyId = $user->company_id;

        // Cek vendor milik company yang sama
        $vendor = Vendor::where('id', $validated['vendor_id'])
            ->where('company_id', $companyId)
            ->first();

        if (! $vendor) {
            return response()->json([
                'message' => 'Vendor tidak ditemukan di perusahaan Anda.',
            ], 403);
        }

        // Hitung subtotal dari items
        $subtotal = 0;
        $preparedItems = [];
        foreach ($validated['items'] as $item) {
            $totalPrice = $item['quantity'] * $item['unit_price'];
            $subtotal += $totalPrice;
            $preparedItems[] = [
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'total_price' => $totalPrice,
            ];
        }

        // Hitung PPN dan total
        $ppnAmount   = round($subtotal * 0.11, 2);
        $totalAmount = $subtotal + $ppnAmount;

        // Tentukan max_approval_level
        $maxApprovalLevel = Invoice::determineApprovalLevel($totalAmount);

        // Simpan invoice + items dalam transaction
        $invoice = DB::transaction(function () use (
            $companyId, $user, $validated,
            $subtotal, $ppnAmount, $totalAmount,
            $maxApprovalLevel, $preparedItems
        ) {
            $invoice = Invoice::create([
                'company_id'           => $companyId,
                'vendor_id'            => $validated['vendor_id'],
                'user_id'              => $user->id,
                'invoice_number'       => $validated['invoice_number'],
                'po_number'            => $validated['po_number'] ?? null,
                'subtotal'             => $subtotal,
                'tax_amount'           => $ppnAmount,
                'discount_amount'      => 0,
                'total_amount'         => $totalAmount,
                'invoice_date'         => $validated['invoice_date'],
                'due_date'             => $validated['due_date'],
                'currency'             => 'IDR',
                'status'               => 'pending',
                'max_approval_level'   => $maxApprovalLevel,
                'current_approval_level' => 0,
                'source'               => 'manual',
                'category'             => $validated['category'],
                'notes'                => $validated['notes'] ?? null,
            ]);

            // Simpan items
            foreach ($preparedItems as $item) {
                InvoiceItem::create(array_merge($item, [
                    'invoice_id' => $invoice->id,
                ]));
            }

            return $invoice;
        });

        // Catat ke activity_logs
        $this->logActivity(
            $user->id, $companyId,
            'invoice_created', 'Input invoice manual ' . $invoice->invoice_number,
            'invoice', $invoice->id
        );

        // Reload dengan items
        $invoice->load('items');

        $levelLabels = [1 => 'Finance Manager', 2 => '+ Direksi', 3 => '+ Komisaris'];

        return response()->json([
            'message' => 'Invoice berhasil dibuat.',
            'invoice' => [
                'id'                     => $invoice->id,
                'invoice_number'         => $invoice->invoice_number,
                'vendor_id'              => $invoice->vendor_id,
                'vendor_name'            => $vendor->name,
                'subtotal'               => $invoice->subtotal,
                'ppn_amount'             => $invoice->tax_amount,
                'total_amount'           => $invoice->total_amount,
                'invoice_date'           => $invoice->invoice_date,
                'due_date'               => $invoice->due_date,
                'status'                 => $invoice->status,
                'source'                 => $invoice->source,
                'category'               => $invoice->category,
                'max_approval_level'     => $invoice->max_approval_level,
                'max_approval_label'     => $levelLabels[$invoice->max_approval_level] ?? null,
                'current_approval_level' => $invoice->current_approval_level,
                'items'                  => $invoice->items->map(fn ($i) => [
                    'id'          => $i->id,
                    'description' => $i->description,
                    'quantity'    => $i->quantity,
                    'unit_price'  => $i->unit_price,
                    'total_price' => $i->total_price,
                ]),
            ],
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════
    // approve() — POST approve invoice (multi-level)
    // ═══════════════════════════════════════════════════════════
    public function approve(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();

        // Cek status invoice harus pending
        if ($invoice->status !== 'pending') {
            return response()->json([
                'message' => 'Invoice sudah diproses dan tidak bisa diapprove lagi.',
            ], 403);
        }

        // Cek role user sesuai level saat ini
        $currentLevel  = $invoice->current_approval_level;
        $allowedRoles  = $this->allowedRolesForLevel($currentLevel);

        if (! in_array($user->role, $allowedRoles)) {
            $expectedRole = $this->levelLabel($currentLevel);
            return response()->json([
                'message' => "Role Anda tidak berwenang approve di level ini. Diperlukan: {$expectedRole}.",
            ], 403);
        }

        // Separation of duties: satu orang tidak boleh approve lebih dari satu level
        // pada invoice yang sama (mis. super_admin meloloskan sendiri L1+L2+L3).
        $alreadyApproved = InvoiceApproval::where('invoice_id', $invoice->id)
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->exists();

        if ($alreadyApproved) {
            return response()->json([
                'message' => 'Anda sudah menyetujui invoice ini. Approval level berikutnya harus oleh orang lain.',
            ], 403);
        }

        // Increment current_approval_level
        $newLevel = $currentLevel + 1;

        // Super Admin "disimpan" untuk approval level tertinggi: ia tidak boleh
        // approve level di bawah max_approval_level selama masih ada approver lain
        // yang berwenang di level ini. Tanpa aturan ini, jika super_admin (satu-
        // satunya yang bisa level tertinggi) ikut approve level awal, ia akan
        // terkunci oleh separation of duties dan invoice tidak bisa tuntas.
        if ($user->role === 'super_admin' && $newLevel < $invoice->max_approval_level) {
            $otherEligible = DB::table('users')
                ->where('company_id', $invoice->company_id)
                ->where('id', '!=', $user->id)
                ->where('is_active', true)
                ->whereIn('role', $allowedRoles)
                ->exists();

            if ($otherEligible) {
                return response()->json([
                    'message' => "Super Admin disimpan untuk approval level tertinggi. Level ini harus disetujui oleh {$this->levelLabel($currentLevel)} lain terlebih dahulu.",
                ], 403);
            }
        }

        // Simpan ke invoice_approvals
        InvoiceApproval::create([
            'invoice_id'     => $invoice->id,
            'user_id'        => $user->id,
            'status'         => 'approved',
            'approval_level' => $newLevel,
            'reviewed_at'    => now(),
            'notes'          => $request->notes,
        ]);

        // Tentukan apakah sudah final
        $isFinal = $newLevel >= $invoice->max_approval_level;
        $newStatus = $isFinal ? 'approved' : 'pending';

        $invoice->update([
            'current_approval_level' => $newLevel,
            'status'                 => $newStatus,
        ]);

        // Catat ke activity_logs
        $this->logActivity(
            $user->id, $invoice->company_id,
            'invoice_approved',
            "Approve level {$newLevel} invoice {$invoice->invoice_number}" . ($isFinal ? ' (FINAL)' : ''),
            'invoice', $invoice->id
        );

        // Kirim notifikasi
        if ($isFinal) {
            // Notifikasi ke pembuat invoice — sudah approved final
            $this->notifyUser($invoice->user_id, 'invoice_approved_final', [
                'message'        => "Invoice {$invoice->invoice_number} telah disetujui sepenuhnya.",
                'invoice_id'     => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status'         => 'approved',
            ], 'invoice', $invoice->id);
        } else {
            // Notifikasi ke approver level berikutnya
            $nextLevelRoles = $this->allowedRolesForLevel($newLevel);
            $nextApprovers = DB::table('users')
                ->where('company_id', $invoice->company_id)
                ->whereIn('role', $nextLevelRoles)
                ->where('is_active', true)
                ->pluck('id');

            foreach ($nextApprovers as $approverId) {
                $this->notifyUser($approverId, 'invoice_awaiting_approval', [
                    'message'        => "Invoice {$invoice->invoice_number} menunggu approval Anda (Level {$newLevel}: {$this->levelLabel($newLevel)}).",
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'approval_level' => $newLevel,
                    'level_label'    => $this->levelLabel($newLevel),
                ], 'invoice', $invoice->id);
            }
        }

        return response()->json([
            'message' => $isFinal
                ? 'Invoice berhasil diapprove (FINAL).'
                : "Invoice berhasil diapprove level {$newLevel}. Menunggu approval {$this->levelLabel($newLevel)}.",
            'invoice' => [
                'id'                     => $invoice->id,
                'invoice_number'         => $invoice->invoice_number,
                'status'                 => $invoice->status,
                'max_approval_level'     => $invoice->max_approval_level,
                'current_approval_level' => $invoice->current_approval_level,
                'is_final'               => $isFinal,
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // reject() — POST reject invoice (langsung rejected)
    // ═══════════════════════════════════════════════════════════
    public function reject(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();

        // Cek status invoice harus pending
        if ($invoice->status !== 'pending') {
            return response()->json([
                'message' => 'Invoice sudah diproses dan tidak bisa direject.',
            ], 403);
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        // Simpan rejection ke invoice_approvals
        InvoiceApproval::create([
            'invoice_id'       => $invoice->id,
            'user_id'          => $user->id,
            'status'           => 'rejected',
            'approval_level'   => $invoice->current_approval_level + 1,
            'reviewed_at'      => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        // Status langsung rejected
        $invoice->update(['status' => 'rejected']);

        // Catat ke activity_logs
        $this->logActivity(
            $user->id, $invoice->company_id,
            'invoice_rejected',
            "Reject invoice {$invoice->invoice_number}: {$request->rejection_reason}",
            'invoice', $invoice->id
        );

        // Kirim notifikasi ke pembuat invoice
        $this->notifyUser($invoice->user_id, 'invoice_rejected', [
            'message'          => "Invoice {$invoice->invoice_number} telah direject.",
            'invoice_id'       => $invoice->id,
            'invoice_number'   => $invoice->invoice_number,
            'status'           => 'rejected',
            'rejection_reason' => $request->rejection_reason,
        ], 'invoice', $invoice->id);

        return response()->json([
            'message' => 'Invoice berhasil direject.',
            'invoice' => [
                'id'               => $invoice->id,
                'invoice_number'   => $invoice->invoice_number,
                'status'           => 'rejected',
                'rejection_reason' => $request->rejection_reason,
            ],
        ]);
    }
}
