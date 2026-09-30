<?php

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\CompanyQuotationItem;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Phase 2 — quotation PDF.
 *
 * The PDF is now rendered by dompdf from the exact same Blade template
 * (`crm.quotation_printable`) as the printable HTML route, so the two can
 * never disagree on a figure. dompdf compresses its content streams, so a
 * plain substring search over the raw bytes finds nothing — `pdfText()`
 * below inflates every stream first so these tests can still assert on the
 * actual rendered facts rather than merely that a route returns 200.
 */
function pdfText(string $bytes): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches);

    $text = '';
    foreach ($matches[1] as $stream) {
        $inflated = @gzuncompress($stream);
        if ($inflated === false) {
            $inflated = @gzinflate($stream);
        }
        if ($inflated !== false) {
            $text .= $inflated;
        }
    }

    return $text;
}

beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->seller = Organization::factory()->provider()->create([
        'name' => 'OralCares Technologies Pvt Ltd',
        'address' => '221B Tech Park Road',
        'city' => 'Pune',
        'state' => 'Maharashtra',
        'gst' => '27AAAAA0000A1Z5',
        'upi_id' => 'oralcares@upi',
        'bank_account_name' => 'OralCares Technologies Pvt Ltd',
        'bank_account_number' => '000123456789',
        'bank_ifsc' => 'HDFC0000123',
        'bank_name' => 'HDFC Bank',
    ]);

    /*
     * A lead's organization_id must reference the provider organisation once
     * one exists — a deal belongs to the org selling it, and there is
     * exactly one seller platform-wide (see `Lead::organizationRole()`'s
     * guard). So the rep, the lead, and the quotation all hang off the same
     * provider org created above; only the lead's own `gstin`/state make it
     * the "buyer" side of the document.
     */
    $this->rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->seller->id]);
    $this->rep->assignRole('Sales Representative');

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->seller->id,
        'assigned_user_id' => $this->rep->id,
        'company_name' => 'Apex Dental Care',
        'contact_name' => 'Dr Amit Patel',
        'email' => 'amit@apexdental.in',
        'city' => 'Ahmedabad',
        'state' => 'Gujarat',
        'gstin' => '24BBBBB1111B2Z6',
    ]);

    $employee = CompanyEmployee::create([
        'organization_id' => $this->seller->id,
        'user_id' => $this->rep->id,
        'employee_code' => 'EMP-PDF1',
        'employment_status' => 'active',
    ]);

    $this->quotation = CompanyQuotation::create([
        'organization_id' => $this->seller->id,
        'lead_id' => $this->lead->id,
        'prepared_by_id' => $employee->id,
        'quotation_number' => 'QT-90210',
        'version' => 2,
        'status' => QuotationStatus::Sent,
        'valid_until' => now()->addDays(30),
        'subtotal' => 100000,
        'discount' => 5000,
        'taxable_amount' => 95000,
        'gst_rate' => 18,
        'tax_type' => 'igst',
        'cgst_amount' => 0,
        'sgst_amount' => 0,
        'igst_amount' => 17100,
        'tax' => 17100,
        'grand_total' => 112100,
        'terms' => 'Payment due within 30 days of acceptance. Prices exclude on-site training.',
        'notes' => 'Includes migration of existing patient records.',
    ]);

    CompanyQuotationItem::create([
        'company_quotation_id' => $this->quotation->id,
        'title' => 'Enterprise Practice Suite',
        'description' => 'Annual licence covering clinical records, billing and appointments.',
        'quantity' => 2,
        'unit_price' => 50000,
        'discount' => 5000,
        'total' => 95000,
    ]);
});

it('renders the printable view with seller branding, GSTINs and the GST breakdown', function () {
    $response = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.printable', $this->quotation));

    $response->assertSuccessful();

    $html = $response->getContent();

    expect($html)->toContain('OralCares Technologies Pvt Ltd')
        ->and($html)->toContain('27AAAAA0000A1Z5')
        ->and($html)->toContain('Apex Dental Care')
        ->and($html)->toContain('24BBBBB1111B2Z6')
        ->and($html)->toContain('Enterprise Practice Suite')
        ->and($html)->toContain('IGST @ 18%')
        ->and($html)->toContain('17,100.00')
        ->and($html)->toContain('112,100.00')
        ->and($html)->toContain('oralcares@upi')
        ->and($html)->toContain('000123456789')
        ->and($html)->toContain('HDFC0000123');
});

it('shows a flat tax line instead of a fabricated GST split on a legacy quotation', function () {
    $legacy = CompanyQuotation::factory()->create([
        'organization_id' => $this->seller->id,
        'lead_id' => $this->lead->id,
        'subtotal' => 20000,
        'discount' => 0,
        'taxable_amount' => null,
        'gst_rate' => null,
        'tax_type' => null,
        'cgst_amount' => 0,
        'sgst_amount' => 0,
        'igst_amount' => 0,
        'tax' => 3600,
        'grand_total' => 23600,
    ]);

    expect($legacy->hasGstBreakdown())->toBeFalse();

    $response = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.printable', $legacy));

    $html = $response->getContent();

    expect($html)->toContain('Estimated Tax')
        ->and($html)->not->toContain('CGST')
        ->and($html)->not->toContain('IGST')
        ->and($html)->toContain('23,600.00');
});

it('does not fabricate payment instructions when the seller has none configured', function () {
    $this->seller->update([
        'upi_id' => null,
        'bank_account_number' => null,
        'bank_account_name' => null,
        'bank_ifsc' => null,
        'bank_name' => null,
    ]);

    $response = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.printable', $this->quotation));

    expect($response->getContent())->not->toContain('Payment Instructions');
});

it('renders without a seller block when no provider organisation exists', function () {
    Organization::query()->whereKey($this->seller->id)->delete();

    $response = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.printable', $this->quotation));

    $response->assertSuccessful();
    expect($response->getContent())->toContain('OralCares Enterprise');
});

it('serves a real PDF over HTTP carrying the same commercial facts as the printable view', function () {
    $response = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.pdf', $this->quotation));

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))->toContain('QT-90210-v2.pdf');

    $bytes = $response->getContent();
    expect($bytes)->toStartWith('%PDF');

    $text = pdfText($bytes);

    expect($text)->toContain('QT-90210')
        ->and($text)->toContain('Apex Dental Care')
        ->and($text)->toContain('Enterprise Practice Suite')
        ->and($text)->toContain('17,100.00')
        ->and($text)->toContain('112,100.00');
});

it('denies the PDF and the printable view to a user without CRM access', function () {
    $outsider = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($outsider)
        ->get(route('company.crm.quotations.pdf', $this->quotation))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->get(route('company.crm.quotations.printable', $this->quotation))
        ->assertForbidden();
});

it('renders a quotation that has no line items without failing', function () {
    $this->quotation->items()->delete();

    $response = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.pdf', $this->quotation));

    $response->assertSuccessful();
    expect($response->getContent())->toStartWith('%PDF');
});
