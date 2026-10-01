<?php

namespace Fleetbase\Quickbooks\Http\Controllers;

use Fleetbase\Quickbooks\Support\Authorizer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class QuickbooksController extends Controller
{
    public function __construct(protected Authorizer $authorizer)
    {
    }

    protected function authorizeQuickbooks(string $permission): void
    {
        $this->authorizer->check($permission);
    }

    /**
     * The company for this request is the signed-in company from the session.
     * A request may repeat that uuid, but it may not name a different company.
     */
    protected function companyUuid(Request $request): string
    {
        $sessionCompany   = (string) session('company', '');
        $requestedCompany = (string) $request->input('company_uuid', '');

        if ($sessionCompany === '') {
            abort(403, 'Sign in to an organization to manage QuickBooks.');
        }

        if ($requestedCompany !== '' && $requestedCompany !== $sessionCompany) {
            abort(403, 'You may only manage the QuickBooks connection for your own organization.');
        }

        return $sessionCompany;
    }
}
