<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\Controllers\VendorPortal;

use App\Utils\Ninja;
use Illuminate\Support\Str;
use App\Utils\Traits\MakesHash;
use App\Utils\Traits\MakesDates;
use Illuminate\Support\Facades\App;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\PurchaseOrderInvitation;
use App\Events\Misc\InvitationWasViewed;
use App\Jobs\Vendor\CreatePurchaseOrderPdf;
use App\Events\PurchaseOrder\PurchaseOrderWasViewed;

/**
 * Class InvitationController.
 */
class InvitationController extends Controller
{
    use MakesHash;
    use MakesDates;

    public function purchaseOrder(string $invitation_key)
    {
        Auth::logout();

        $invitation = PurchaseOrderInvitation::withTrashed()
                                    ->where('key', $invitation_key)
                                    ->whereHas('purchase_order', function ($query) {
                                        $query->where('is_deleted', 0);
                                    })
                                    ->with('contact.vendor')
                                    ->first();

        if (!$invitation) {
            return abort(404, 'The resource is no longer available.');
        }

        if ($invitation->contact->trashed()) {
            $invitation->contact->restore();
        }

        $vendor_contact = $invitation->contact;
        $entity = 'purchase_order';

        if (empty($vendor_contact->email)) {
            $vendor_contact->email = Str::random(15) . "@example.com";
        } $vendor_contact->save();

        if (request()->has('vendor_hash') && request()->input('vendor_hash') == $invitation->contact->vendor->vendor_hash) {
            request()->session()->invalidate();
            auth()->guard('vendor')->loginUsingId($vendor_contact->id, true);
        } else {
            request()->session()->invalidate();
            auth()->guard('vendor')->loginUsingId($vendor_contact->id, true);
        }

        session()->put('is_silent', request()->has('silent'));

        if (auth()->guard('vendor')->user() && ! session()->get('is_silent') && ! $invitation->viewed_date) {
            $invitation->markViewed();
            event(new InvitationWasViewed($invitation->purchase_order, $invitation, $invitation->company, Ninja::eventVars()));
            event(new PurchaseOrderWasViewed($invitation, $invitation->company, Ninja::eventVars()));
        } else {
            return redirect()->route('vendor.'.$entity.'.show', [$entity => $this->encodePrimaryKey($invitation->purchase_order_id), 'silent' => session()->get('is_silent')]);
        }

        return redirect()->route('vendor.'.$entity.'.show', [$entity => $this->encodePrimaryKey($invitation->purchase_order_id)]);
    }

    public function download(string $invitation_key)
    {
        $invitation = PurchaseOrderInvitation::withTrashed()
                            ->where('key', $invitation_key)
                            ->with('contact.vendor')
                            ->firstOrFail();

        if (!$invitation) {
            return response()->json(["message" => "no record found"], 400);
        }

        App::setLocale($invitation->contact->preferredLocale());

        $file_name = $invitation->purchase_order->numberFormatter().'.pdf';

        $file = (new CreatePurchaseOrderPdf($invitation))->rawPdf();

        $headers = ['Content-Type' => 'application/pdf'];

        if (request()->input('inline') == 'true') {
            $headers = array_merge($headers, ['Content-Disposition' => 'inline']);
        }

        return response()->streamDownload(function () use ($file) {
            echo $file;
        }, $file_name, $headers);
    }
}
