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

namespace App\Mail\Admin;

use App\Libraries\MultiDB;
use App\Models\Company;
use App\Models\Quote;
use App\Utils\Ninja;
use App\Utils\Number;
use Illuminate\Support\Facades\App;
use stdClass;

class QuoteExpiredObject
{

    public function __construct(public Quote $quote, public Company $company, public bool $use_react_url)
    {
    }

    public function build()
    {
        MultiDB::setDb($this->company->db);

        if (! $this->quote) {
            return;
        }

        App::forgetInstance('translator');
        /* Init a new copy of the translator*/
        $t = app('translator');
        /* Set the locale*/
        App::setLocale($this->company->getLocale());
        /* Set customized translations _NOW_ */
        $t->replace(Ninja::transformTranslations($this->company->settings));

        $mail_obj = new stdClass;
        $mail_obj->amount = $this->getAmount();
        $mail_obj->subject = $this->getSubject();
        $mail_obj->data = $this->getData();
        $mail_obj->markdown = 'email.admin.generic';
        $mail_obj->tag = $this->company->company_key;

        return $mail_obj;
    }

    private function getAmount()
    {
        return Number::formatMoney($this->quote->amount, $this->quote->client);
    }

    private function getSubject()
    {
        return
            ctrans(
                'texts.notification_quote_expired_subject',
                [
                    'client' => $this->quote->client->present()->name(),
                    'invoice' => $this->quote->number,
                ]
            );
    }

    private function getData()
    {
        $settings = $this->quote->client->getMergedSettings();

        $data = [
            'title' => $this->getSubject(),
            'message' => ctrans(
                'texts.notification_quote_expired',
                [
                    'amount' => $this->getAmount(),
                    'client' => $this->quote->client->present()->name(),
                    'invoice' => $this->quote->number,
                ]
            ),
            'url' => $this->quote->invitations->first()->getAdminLink($this->use_react_url),
            'button' => $this->use_react_url ? ctrans('texts.view_quote') : ctrans('texts.login'),
            'signature' => $settings->email_signature,
            'logo' => $this->company->present()->logo(),
            'settings' => $settings,
            'whitelabel' => $this->company->account->isPaid() ? true : false,
        ];

        return $data;
    }
}
