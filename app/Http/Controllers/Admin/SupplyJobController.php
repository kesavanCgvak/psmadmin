<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\EmailHelper;
use App\Models\JobOffer;
use App\Models\SupplyJobProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\SupplyJob;
use Illuminate\Http\Request;

class SupplyJobController extends Controller
{
    /**
     * Display a listing of supply jobs.
     */
    public function index()
    {
        $supplyJobs = SupplyJob::with([
            'rentalJob.user.profile',
            'rentalJob.user.company',
            'provider',
            'cancelledByUser',
            'products.product.brand',
            'products.product.category'
        ])
        ->withCount(['products', 'comments'])
        ->orderBy('created_at', 'desc')
        ->get();

        return view('admin.supply-jobs.index', compact('supplyJobs'));
    }

    /**
     * Display the specified supply job (read-only).
     */
    public function show(SupplyJob $supplyJob)
    {
        $supplyJob->load([
            'rentalJob.user.profile',
            'rentalJob.user.company',
            'rentalJob.user.company.defaultContact.profile',
            'rentalJob.products.product.brand',
            'provider.region',
            'provider.country',
            'provider.city',
            'provider.currency',
            'provider.defaultContact.profile',
            'cancelledByUser',
            'products.product.brand',
            'products.product.category',
            'products.product.subCategory',
            'supplyJobOffers.senderCompany',
            'supplyJobOffers.receiverCompany',
            'supplyJobOffers.currency',
            'comments.sender.profile',
            'comments.recipient.profile'
        ]);

        return view('admin.supply-jobs.show', compact('supplyJob'));
    }

    /**
     * Admin "delete" action: mark supply job as admin cancelled.
     */
    public function adminCancel(Request $request, SupplyJob $supplyJob)
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'send_delete_email' => ['nullable', 'boolean'],
        ]);

        if ($supplyJob->status === 'cancelled' && $this->isCancelledByAdmin($supplyJob)) {
            return redirect()
                ->route('admin.supply-jobs.show', $supplyJob)
                ->with('error', 'This supply job is already admin cancelled.');
        }

        if (in_array($supplyJob->status, ['completed', 'rated'], true)) {
            return redirect()
                ->route('admin.supply-jobs.show', $supplyJob)
                ->with('error', 'Completed or rated jobs cannot be admin cancelled.');
        }

        $adminUser = auth()->user();
        $reason = trim((string) ($validated['reason'] ?? ''));
        $sendDeleteEmail = (bool) ($validated['send_delete_email'] ?? false);

        DB::transaction(function () use ($supplyJob, $adminUser, $reason) {
            $supplyJob->status = 'cancelled';
            $supplyJob->cancelled_by = $adminUser->id;
            if ($reason !== '') {
                $supplyJob->notes = $reason;
            }
            $supplyJob->save();

            JobOffer::where('supply_job_id', $supplyJob->id)->update(['status' => 'cancelled']);
        });

        if ($sendDeleteEmail) {
            $this->sendAdminDeletedNotifications($supplyJob->fresh([
                'provider.defaultContact.profile',
                'rentalJob.user.profile',
                'rentalJob.user.company.defaultContact.profile',
            ]), $reason);
        }

        return redirect()
            ->route('admin.supply-jobs.show', $supplyJob)
            ->with('success', $sendDeleteEmail
                ? 'Supply job marked as Admin Cancelled and delete email notifications were sent.'
                : 'Supply job marked as Admin Cancelled.');
    }

    private function isCancelledByAdmin(SupplyJob $supplyJob): bool
    {
        if ($supplyJob->status !== 'cancelled' || !$supplyJob->cancelled_by) {
            return false;
        }

        return User::where('id', $supplyJob->cancelled_by)->where('is_admin', 1)->exists();
    }

    private function sendAdminDeletedNotifications(SupplyJob $supplyJob, string $reason = ''): void
    {
        $renterEmails = array_filter([
            $supplyJob->rentalJob?->user?->profile?->email,
            $supplyJob->rentalJob?->user?->company?->defaultContact?->profile?->email,
        ]);

        $providerEmails = array_filter([
            $supplyJob->provider?->defaultContact?->profile?->email,
        ]);

        $allEmails = array_unique(array_merge($renterEmails, $providerEmails));
        if (empty($allEmails)) {
            return;
        }

        $offerProducts = SupplyJobProduct::with(['product.getEquipment'])
            ->where('supply_job_id', $supplyJob->id)
            ->get()
            ->map(function ($item) {
                return [
                    'psm_code' => $item->product->psm_code ?? '—',
                    'model' => $item->product->model ?? '—',
                    'software_code' => $item->product->getEquipment->software_code ?? '—',
                    'quantity' => $item->offered_quantity ?? $item->quantity ?? 0,
                    'price' => $item->price_per_unit ?? $item->price ?? 0,
                ];
            })
            ->toArray();

        $reasonDisplay = $reason !== '' ? '<p><strong>Reason:</strong> ' . e($reason) . '</p>' : '';
        $productsSection = '';
        if (!empty($offerProducts)) {
            $grandTotal = 0;
            $rows = '';
            foreach ($offerProducts as $product) {
                $qty = (int) ($product['quantity'] ?? 0);
                $price = (float) ($product['price'] ?? 0);
                $total = $qty * $price;
                $grandTotal += $total;
                $rows .= '<tr><td>' . e($product['psm_code']) . '</td><td>' . e($product['model']) . '</td><td>'
                    . e($product['software_code']) . '</td><td>' . $qty . '</td><td>'
                    . number_format($price, 2) . '</td><td>' . number_format($total, 2) . '</td></tr>';
            }

            $productsSection = '<h3>Deleted Equipment Details</h3><table width="100%" cellpadding="8" cellspacing="0"><thead><tr>'
                . '<th align="left">PSM Code</th><th align="left">Model</th><th align="left">Software Code</th>'
                . '<th align="left">Qty</th><th align="left">Price</th><th align="left">Total Price</th></tr></thead><tbody>'
                . $rows
                . '<tr><td colspan="5" align="right"><strong>Grand Total</strong></td><td><strong>' . number_format($grandTotal, 2)
                . '</strong></td></tr></tbody></table>';
        }

        $mailData = [
            'provider' => $supplyJob->provider?->name ?? 'Provider',
            'supply_job_name' => $supplyJob->rentalJob?->name ?? 'Supply Job',
            'status' => 'Admin Cancelled',
            'reason_display' => $reasonDisplay,
            'date' => now()->format('d M Y, h:i A'),
            'products_section' => $productsSection,
        ];

        foreach ($allEmails as $email) {
            EmailHelper::send('supplyJobDeletedByAdmin', $mailData, function ($message) use ($email) {
                $message->to($email)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });
        }
    }
}

