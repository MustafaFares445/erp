<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Data\Crm\CampaignData;
use App\Data\Crm\InteractionData;
use App\Data\Crm\LeadData;
use App\Data\Sales\OpportunityData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignResponseType;
use App\Enums\CustomerProvisioningSource;
use App\Enums\InteractionDirection;
use App\Enums\InteractionOutcome;
use App\Enums\InteractionType;
use App\Enums\LeadDisqualificationReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\NotificationChannel;
use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityOrigin;
use App\Enums\OpportunityStage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CustomerProfile;
use App\Models\CustomerQuotationRequest;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\NotificationTemplate;
use App\Models\ProductVariant;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Crm\CampaignDispatchService;
use App\Services\Crm\CampaignResponseService;
use App\Services\Crm\CampaignService;
use App\Services\Crm\CustomerAccountProvisioningService;
use App\Services\Crm\CustomerApprovalService;
use App\Services\Crm\CustomerProfileChangeRequestService;
use App\Services\Crm\CustomerQuotationRequestService;
use App\Services\Crm\InteractionService;
use App\Services\Crm\LeadConversionService;
use App\Services\Crm\LeadService;
use App\Services\Sales\OpportunityService;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * CRM story of the demo month: 15 leads walking the real ladder (three converted into
 * customers), logged interactions, opportunities across every funnel stage, six campaigns
 * in every lifecycle state, customer onboarding approvals, profile-change and quotation
 * requests. Everything goes through the CRM / Sales services with the clock pinned to the
 * scene, so audit rows, notifications and timestamps carry the real day.
 *
 * Run after DemoMasterDataSeeder. Does not depend on sales, purchasing or inventory data.
 */
final class DemoCrmMonthSeeder extends DemoSeeder
{
    /** Marker for idempotency: the first lead of the story. */
    private const string MarkerEmail = 'hamad.alsuwaidi@oasis-dental.example';

    /**
     * Lead roster. key => source, detail, first, last, company, title, email, phone, assignee employee number (or null).
     *
     * @var array<string, array{0: LeadSource, 1: string, 2: string, 3: string, 4: string, 5: string, 6: ?string, 7: string, 8: ?string}>
     */
    private const array Leads = [
        'L01' => [LeadSource::Website, 'Contact form: surgical guide resin enquiry', 'Hamad', 'Al Suwaidi', 'Oasis Dental Studio', 'Owner Dentist', self::MarkerEmail, '+971500100101', '001'],
        'L02' => [LeadSource::Referral, 'Referred by Bright Smile Dental Clinic', 'Reem', 'Al Falasi', 'Marina Orthodontics Clinic', 'Clinic Director', 'reem.alfalasi@marina-ortho.example', '+971500100102', '002'],
        'L03' => [LeadSource::Exhibition, 'Dubai Dental Expo 2026 booth visit', 'Tariq', 'Bin Zayed', 'Gulf Implant Center', 'Chief Implantologist', 'tariq.binzayed@gulf-implant.example', '+971500100103', '003'],
        'L04' => [LeadSource::Partner, 'Introduced by DentalTech Distribution', 'Salma', 'Karam', 'Elite Smiles Group', 'Procurement Lead', 'salma.karam@elite-smiles.example', '+971500100104', '001'],
        'L05' => [LeadSource::Campaign, 'September newsletter sign-up form', 'Nabil', 'Haddad', 'Dental Hub Trading', 'Purchasing Manager', 'nabil.haddad@dental-hub.example', '+971500100105', '002'],
        'L06' => [LeadSource::ColdCall, 'Outbound call list: Sharjah labs', 'Maha', 'Al Ketbi', 'Sharjah Smile Lab', 'Lab Manager', 'maha.alketbi@sharjah-smile-lab.example', '+971500100106', '003'],
        'L07' => [LeadSource::Website, 'Catalogue download: starter kits', 'Yousef', 'Rahman', 'Fujairah Care Dental', 'Dentist', 'yousef.rahman@fujairah-care.example', '+971500100107', '004'],
        'L08' => [LeadSource::FieldObservation, 'Spotted new clinic fit-out in Ajman', 'Hind', 'Al Shamsi', 'Ajman Dental Supplies Co', 'Owner', null, '+971500100108', '003'],
        'L09' => [LeadSource::Referral, 'Referred by Elite Surgical Center', 'Walid', 'Nasser', 'Desert Rose Clinic', 'Clinic Manager', 'walid.nasser@desert-rose.example', '+971500100109', '006'],
        'L10' => [LeadSource::Website, 'Quote request form', 'Sana', 'Qureshi', 'RAK Dental Laboratory', 'Lab Technician', 'sana.qureshi@rak-dental-lab.example', '+971500100110', null],
        'L11' => [LeadSource::Other, 'Walk-in at the Dubai showroom', 'Ibrahim', 'Khoury', 'Khoury Medical Supplies', 'Buyer', 'ibrahim.khoury@khoury-medical.example', '+971500100111', null],
        'L12' => [LeadSource::ColdCall, 'Outbound call list: Dubai clinics', 'Laila', 'Mustafa', 'Budget Smiles Clinic', 'Owner', null, '+971500100112', '004'],
        'L13' => [LeadSource::Exhibition, 'Dubai Dental Expo 2026 booth visit', 'Faris', 'Al Dhaheri', 'Faris Dental Equipment Reseller', 'Sales Director', 'faris.aldhaheri@faris-dental.example', '+971500100113', '004'],
        'L14' => [LeadSource::Referral, 'Referred by Harmony Dental Clinic', 'Amal', 'Siddiqui', 'Northern Emirates Clinic', 'Medical Director', 'amal.siddiqui@northern-emirates-clinic.example', '+971500100114', '001'],
        'L15' => [LeadSource::Website, 'Contact form: price list request', 'Zaid', 'Mahmoud', 'Zaid Dental Center', 'Dentist', 'zaid.mahmoud@zaid-dental.example', '+971500100115', '006'],
    ];

    /**
     * Customers created from converted leads (customer data for LeadConversionService::convert).
     *
     * @var array<string, array{code: string, username: string, login: string, company: string, email: string, phone: string, address: string, city: string, lat: float, lng: float}>
     */
    private const array Conversions = [
        'L01' => ['code' => 'DEMO-CUST-016', 'username' => 'demo-cust-016', 'login' => 'demo.cust016@ierp.test', 'company' => 'Oasis Dental Studio', 'email' => 'accounts.cust016@clinic.test', 'phone' => '+97150700016', 'address' => 'Building 16, Dubai Healthcare District', 'city' => 'Dubai', 'lat' => 25.2301, 'lng' => 55.3105],
        'L02' => ['code' => 'DEMO-CUST-017', 'username' => 'demo-cust-017', 'login' => 'demo.cust017@ierp.test', 'company' => 'Marina Orthodontics Clinic', 'email' => 'accounts.cust017@clinic.test', 'phone' => '+97150700017', 'address' => 'Building 17, Dubai Marina', 'city' => 'Dubai', 'lat' => 25.0805, 'lng' => 55.1403],
        'L03' => ['code' => 'DEMO-CUST-018', 'username' => 'demo-cust-018', 'login' => 'demo.cust018@ierp.test', 'company' => 'Gulf Implant Center', 'email' => 'accounts.cust018@clinic.test', 'phone' => '+97150700018', 'address' => 'Building 18, Abu Dhabi Healthcare District', 'city' => 'Abu Dhabi', 'lat' => 24.4880, 'lng' => 54.3550],
    ];

    /** Self-registered customers waiting on approval decisions. */
    private const array Onboarding = [
        'DEMO-CUST-019' => ['company' => 'Sunrise Dental Clinic', 'city' => 'Dubai', 'lat' => 25.2100, 'lng' => 55.2800],
        'DEMO-CUST-020' => ['company' => 'Coastal Dental Laboratory', 'city' => 'Ajman', 'lat' => 25.4110, 'lng' => 55.4450],
        'DEMO-CUST-021' => ['company' => 'Blue Pearl Polyclinic', 'city' => 'Sharjah', 'lat' => 25.3400, 'lng' => 55.4000],
    ];

    private DemoContext $context;

    private int $codeSequence = 9000;

    /** @var array<string, Lead> */
    private array $leads = [];

    /** @var array<string, Interaction> last interaction per lead key */
    private array $lastInteraction = [];

    /** @var array<string, SalesOpportunity> */
    private array $opportunities = [];

    /** @var array<string, Campaign> */
    private array $campaigns = [];

    protected function seed(DemoContext $context): void
    {
        if (Lead::query()->where('email', self::MarkerEmail)->exists()) {
            $this->note('CRM month story already seeded (lead '.self::MarkerEmail.' exists) - skipping.');

            return;
        }

        $this->context = $context;

        // Deterministic customer codes for lead conversion (the service otherwise draws random_int).
        app()->bind(
            CustomerAccountProvisioningService::class,
            fn (): CustomerAccountProvisioningService => new CustomerAccountProvisioningService(fn (int $min, int $max): int => $this->codeSequence++),
        );

        $this->seedCampaignTemplates();
        $this->timeline();
    }

    private function timeline(): void
    {
        // --- Week 1 (Sep 4 - Sep 11)
        $this->campaign('A', 'Autumn Clinic Newsletter', CampaignChannel::Email, 'crm.campaign.demo.newsletter', '2026-09-07 10:00');
        $this->lead('L01', '2026-09-04 10:00');
        $this->interact('L01', '2026-09-04 14:20', InteractionType::Email, InteractionDirection::Inbound, InteractionOutcome::Positive, 'Website enquiry: surgical guide resin and implant fixtures', 'Asked for price list and delivery times to Dubai.');
        $this->lead('L02', '2026-09-07 09:30');
        $this->lead('L06', '2026-09-07 10:15');
        $this->interact('L01', '2026-09-07 11:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Intro call with Dr. Hamad', 'Runs a 4-chair practice, prints surgical guides in house.', LeadStatus::Contacted);
        $this->interact('L02', '2026-09-08 10:30', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Intro call after referral', 'Bright Smile vouched for our model resin quality.', LeadStatus::Contacted);
        $this->lead('L04', '2026-09-08 11:30');
        $this->interact('L06', '2026-09-08 15:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::NoAnswer, 'Cold call to lab manager', null);
        $this->lead('L12', '2026-09-08 16:00');
        $this->lead('L03', '2026-09-09 09:00');
        $this->lead('L13', '2026-09-09 10:00');
        $this->interact('L03', '2026-09-09 11:20', InteractionType::FieldVisit, InteractionDirection::Inbound, InteractionOutcome::Positive, 'Visited our booth at Dubai Dental Expo', 'Interested in implant fixtures and guided-surgery resin.', LeadStatus::Contacted);
        $this->interact('L01', '2026-09-09 14:00', InteractionType::Demo, InteractionDirection::Outbound, InteractionOutcome::Positive, 'On-site demo of the surgical guide workflow', 'Dr. Hamad wants to onboard as a customer this week.', LeadStatus::Qualified);
        $this->opportunity('OPP-A', '2026-09-09 15:30', 'Surgical guide resin starter contract', 'Starter contract for surgical guide resin and healing abutments', 2400000, '2026-09-30', 30, '001', leadKey: 'L01');
        $this->interact('L04', '2026-09-09 15:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Intro call about group purchasing', null, LeadStatus::Contacted);
        $this->interact('L12', '2026-09-09 16:30', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Neutral, 'First call with clinic owner', 'Open to a quote but budget is tight.', LeadStatus::Contacted);
        $this->campaignRecipients('A', '2026-09-09 17:30', ['DEMO-CUST-001', 'DEMO-CUST-002', 'DEMO-CUST-003', 'DEMO-CUST-004'], ['L04', 'L06', 'L12']);
        $this->interactCustomer('DEMO-CUST-003', '2026-09-10 11:00', InteractionType::Demo, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Surgical drill handpiece demo at the clinic', 'Surgeons liked the torque control.');
        $this->opportunity('OPP-C', '2026-09-10 12:00', 'Surgical drill replacement programme', 'Replace four ageing handpieces over two quarters', 9600000, '2026-11-15', 40, '002', customerCode: 'DEMO-CUST-003');
        $this->stage('OPP-C', '2026-09-12 10:00', OpportunityStage::NeedsAnalysis);
        $this->lead('L05', '2026-09-10 09:00');
        $this->campaignDispatch('A', '2026-09-10 10:00');
        $this->campaignResponse('A', 'DEMO-CUST-001', '2026-09-10 11:30', CampaignResponseType::Opened);
        $this->campaignResponse('A', 'DEMO-CUST-002', '2026-09-10 12:10', CampaignResponseType::Opened);
        $this->campaignResponse('A', 'DEMO-CUST-002', '2026-09-10 14:00', CampaignResponseType::Clicked);
        $this->campaignResponse('A', 'L04', '2026-09-10 15:45', CampaignResponseType::Opened);
        $this->campaignResponse('A', 'DEMO-CUST-002', '2026-09-11 09:30', CampaignResponseType::Interested, 'Wants a quote for impression materials.');
        $this->campaignResponse('A', 'L04', '2026-09-11 10:00', CampaignResponseType::Clicked);
        $this->campaignResponse('A', 'L04', '2026-09-11 10:05', CampaignResponseType::Interested, 'Asked for a group-purchasing price list.');
        $this->campaignResponse('A', 'DEMO-CUST-003', '2026-09-11 13:20', CampaignResponseType::Opened);
        $this->campaignResponse('A', 'L06', '2026-09-11 16:00', CampaignResponseType::Opened);
        $this->interact('L13', '2026-09-11 10:00', InteractionType::Meeting, InteractionDirection::Outbound, InteractionOutcome::Negative, 'Meeting at the reseller showroom', 'They resell equipment and will not buy consumables for end use.');
        $this->disqualify('L13', '2026-09-11 10:30', LeadDisqualificationReason::NoFit, 'Reseller, not an end user of consumables.');
        $this->interact('L02', '2026-09-11 11:00', InteractionType::Email, InteractionDirection::Outbound, InteractionOutcome::FollowUp, 'Sent model resin price list', 'Will review with partners and revert.');
        $this->opportunity('OPP-B', '2026-09-11 11:30', 'Resin price renegotiation', 'Future Health asked to revisit resin pricing for 2026-27', 1200000, '2026-10-05', 35, '003', customerCode: 'DEMO-CUST-010');
        $this->lead('L14', '2026-09-11 14:00');
        $this->lead('L15', '2026-09-12 09:00');
        $this->campaignResponse('A', 'DEMO-CUST-004', '2026-09-12 10:00', CampaignResponseType::Unsubscribed, 'Asked to stop marketing emails.');
        $this->quotationRequest('QR-1', '2026-09-12 14:30', 'DEMO-CUST-004', [['P004', '35X10', 10, 'Trial batch of fixtures'], ['P005', '35MM', 20, null]], 'Please quote for a trial batch before the standing order.');

        // --- Week 2 (Sep 14 - Sep 18)
        $this->quotationRequestReview('QR-1', '2026-09-13 10:00');
        $this->convert('L01', '2026-09-14 10:00');
        $this->interact('L15', '2026-09-14 11:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::NoAnswer, 'Call to request a callback time', null);
        $this->lead('L08', '2026-09-14 13:00');
        $this->interact('L14', '2026-09-14 14:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Intro call with medical director', 'Clinic is renovating; asked to talk again later.', LeadStatus::Contacted);
        $this->quotationRequestConvert('QR-1', '2026-09-14 15:30');
        $this->stage('OPP-A', '2026-09-14 16:00', OpportunityStage::Proposal);
        $this->interact('L08', '2026-09-15 09:30', InteractionType::FieldVisit, InteractionDirection::Outbound, InteractionOutcome::Neutral, 'Walk-in visit at the new fit-out site', 'Owner away, left catalogue with the foreman.', LeadStatus::Contacted);
        $this->interact('L02', '2026-09-15 11:00', InteractionType::Meeting, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Meeting at the clinic with partners', 'Agreed to move their model resin supply to us.', LeadStatus::Qualified);
        $this->opportunity('OPP-H', '2026-09-15 14:00', 'Orthodontic model resin supply', 'Monthly model resin supply for the new clinic', 2100000, '2026-10-20', 45, '002', leadKey: 'L02');
        $this->interact('L12', '2026-09-16 10:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Negative, 'Budget follow-up call', 'Owner says no budget for new suppliers this year.');
        $this->disqualify('L12', '2026-09-16 10:30', LeadDisqualificationReason::NoBudget, 'Budget frozen until next year.');
        $this->interact('L04', '2026-09-16 11:00', InteractionType::Demo, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Implant workflow demo for the group', 'Three clinics attended; asked for a package proposal.');
        $this->opportunity('OPP-D', '2026-09-16 12:00', 'Full implant workflow package', 'Package for three Elite Smiles clinics', 14000000, '2026-11-30', 40, '001', leadKey: 'L04');
        $this->stage('OPP-D', '2026-09-16 13:00', OpportunityStage::Demo);
        $this->interactCustomer('DEMO-CUST-010', '2026-09-16 15:00', InteractionType::Call, InteractionDirection::Inbound, InteractionOutcome::Negative, 'Customer called about resin pricing', 'Compared us with a competitor quote.');
        $this->customerOnboarding('DEMO-CUST-019', '2026-09-16 11:00');
        $this->interact('L03', '2026-09-17 10:00', InteractionType::Meeting, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Clinic meeting with implant team', 'Ready to register and place a first order.', LeadStatus::Qualified);
        $this->stage('OPP-A', '2026-09-17 11:00', OpportunityStage::Negotiation);
        $this->interact('L15', '2026-09-17 14:30', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::NoAnswer, 'Second callback attempt', null);
        $this->interact('L06', '2026-09-17 15:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Neutral, 'Spoke to lab manager', 'Wants to compare against current supplier; no rush.', LeadStatus::Contacted);
        $this->customerOnboarding('DEMO-CUST-020', '2026-09-17 10:00');
        $this->changeRequest('2026-09-17 11:30', 'DEMO-CUST-002', ['address' => 'Unit 4, Al Wasl Business Bay, Dubai', 'city' => 'Dubai'], 'Clinic relocated to a new floor.', 'approve', '2026-09-18 09:30');
        $this->customerOnboarding('DEMO-CUST-021', '2026-09-18 09:00');
        $this->interact('L05', '2026-09-18 10:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Intro call with purchasing manager', 'Newsletter reader; buys from two distributors today.', LeadStatus::Contacted);
        $this->interact('L05', '2026-09-18 15:00', InteractionType::Email, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Sent product range and terms', 'Pricing accepted in principle; waiting for internal sign-off.', LeadStatus::Qualified);
        $this->stage('OPP-B', '2026-09-18 16:00', OpportunityStage::Proposal);
        $this->customerOnboardingDecision('DEMO-CUST-020', '2026-09-18 11:00', 'changes');

        // --- Week 3 (Sep 19 - Sep 25)
        $this->convert('L02', '2026-09-19 10:00');
        $this->customerOnboardingDecision('DEMO-CUST-021', '2026-09-19 14:00', 'reject');
        $this->quotationRequest('QR-2', '2026-09-19 15:00', 'DEMO-CUST-012', [['P019', 'HANDPIECE', 1, 'Replacement for unit in OR 2']], 'Needed before month end.');
        $this->campaign('B', 'September Resin Promotion', CampaignChannel::Email, 'crm.campaign.demo.resin_promo', '2026-09-20 09:00', '2026-09-22 10:00');
        $this->stage('OPP-A', '2026-09-20 11:00', OpportunityStage::ClosedWon, OpportunityCloseReason::WonAfterNegotiation, 'Signed the starter supply contract after a 3% volume concession.');
        $this->quotationRequestReject('QR-2', '2026-09-21 10:30', 'Item is on allocation; sales will propose an alternative.');
        $this->changeRequest('2026-09-20 14:00', 'DEMO-CUST-007', ['company_name' => 'Prime Dental Laboratory FZ-LLC'], 'Legal entity renamed.', 'reject', '2026-09-21 11:00', 'Supporting trade licence not attached.');
        $this->lead('L07', '2026-09-21 09:00');
        $this->interactCustomer('DEMO-CUST-008', '2026-09-21 12:00', InteractionType::Meeting, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Annual supply review with clinic manager', 'Wants a fixed-price impression materials contract.');
        $this->opportunity('OPP-E', '2026-09-21 13:00', 'Annual impression materials supply', 'Fixed-price annual supply of impression materials', 3800000, '2026-10-31', 55, '004', customerCode: 'DEMO-CUST-008');
        $this->stage('OPP-E', '2026-09-21 14:00', OpportunityStage::Proposal);
        $this->campaignRecipients('B', '2026-09-21 15:00', ['DEMO-CUST-001', 'DEMO-CUST-002', 'DEMO-CUST-003', 'DEMO-CUST-004', 'DEMO-CUST-005', 'DEMO-CUST-006'], ['L05', 'L07', 'L08']);
        $this->interact('L07', '2026-09-22 10:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Intro call with the dentist', 'Opening a second chair; needs a starter kit.', LeadStatus::Contacted);
        $this->interact('L04', '2026-09-22 11:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Positive, 'Follow-up on package proposal', 'Proposal accepted for review by their board.', LeadStatus::Qualified);
        $this->opportunity('OPP-G', '2026-09-22 11:30', 'Starter resin and implant kit', 'Starter consumables for a new treatment room', 1800000, '2026-10-25', 25, '004', leadKey: 'L07');
        $this->campaignDispatch('B', '2026-09-22 10:00');
        $this->campaignResponse('B', 'DEMO-CUST-001', '2026-09-22 11:00', CampaignResponseType::Opened);
        $this->campaignResponse('B', 'DEMO-CUST-003', '2026-09-22 11:40', CampaignResponseType::Opened);
        $this->campaignResponse('B', 'L05', '2026-09-22 14:10', CampaignResponseType::Opened);
        $this->campaignResponse('B', 'DEMO-CUST-001', '2026-09-22 14:30', CampaignResponseType::Clicked);
        $this->campaignResponse('B', 'DEMO-CUST-005', '2026-09-23 09:45', CampaignResponseType::Opened);
        $this->campaignResponse('B', 'L07', '2026-09-23 10:15', CampaignResponseType::Opened);
        $this->campaignResponse('B', 'L05', '2026-09-23 10:30', CampaignResponseType::Clicked);
        $this->campaignResponse('B', 'L05', '2026-09-23 10:40', CampaignResponseType::Interested, 'Wants volume pricing on model resin.');
        $this->campaignResponse('B', 'DEMO-CUST-005', '2026-09-23 15:00', CampaignResponseType::Interested, 'Asked for a sample of the new resin.');
        $this->campaignResponse('B', 'L07', '2026-09-24 09:00', CampaignResponseType::Replied, 'Replied asking for delivery times to Fujairah.');
        $this->convert('L03', '2026-09-24 10:00');
        $this->interactCustomer('DEMO-CUST-001', '2026-09-23 10:00', InteractionType::FieldVisit, InteractionDirection::Outbound, InteractionOutcome::Neutral, 'Account visit to review Q4 volumes', null);
        $this->opportunity('OPP-F', '2026-09-23 11:00', 'Implant fixtures bulk purchase Q4', 'Bulk order of fixtures for Q4 at tiered pricing', 8500000, '2026-10-15', 50, '001', customerCode: 'DEMO-CUST-001');
        $this->advanceInboundSampleOpportunity();
        $this->stage('OPP-F', '2026-09-25 10:00', OpportunityStage::Proposal);
        $this->lead('L09', '2026-09-25 09:00');
        $this->changeRequest('2026-09-25 11:00', 'DEMO-CUST-009', ['city' => 'Abu Dhabi', 'address' => 'Tower 2, Corniche Road'], 'Second branch is now the billing address.', null, null);
        $this->campaign('C', 'Maintenance Kit Flash Offer', CampaignChannel::Sms, 'crm.campaign.demo.flash_sms', '2026-09-25 14:00');
        $this->campaignRecipients('C', '2026-09-25 14:30', ['DEMO-CUST-001', 'DEMO-CUST-002', 'DEMO-CUST-003'], []);
        $this->campaignDispatch('C', '2026-09-25 15:00');

        // --- Week 4 (Sep 26 - Oct 3)
        $this->stage('OPP-B', '2026-09-26 10:00', OpportunityStage::ClosedLost, OpportunityCloseReason::LostOnPrice, 'Customer moved to a competitor quote 6% below ours.');
        $this->changeRequest('2026-09-26 12:00', 'DEMO-CUST-011', ['city' => 'Dubai'], 'Wrong city entered at signup.', 'cancel', '2026-09-27 09:00');
        $this->campaign('F', 'Dental Expo Follow-up', CampaignChannel::Email, 'crm.campaign.demo.expo_followup', '2026-09-27 09:00', '2026-10-05 09:00');
        $this->quotationRequest('QR-3', '2026-09-26 15:30', 'DEMO-CUST-009', [['P001', '1L', 6, 'For the new printing room']], null);
        $this->lead('L10', '2026-09-28 09:30');
        $this->interact('L10', '2026-09-28 10:30', InteractionType::Email, InteractionDirection::Inbound, null, 'Requested the catalogue and a quotation', null);
        $this->interact('L15', '2026-09-28 16:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::NoAnswer, 'Final callback attempt', null);
        $this->disqualify('L15', '2026-09-28 16:20', LeadDisqualificationReason::NoResponse, 'Three call attempts, no reply.');
        $this->interact('L07', '2026-09-29 10:00', InteractionType::Email, InteractionDirection::Outbound, InteractionOutcome::FollowUp, 'Sent the starter kit quotation', 'Decision expected next week.');
        $this->interactCustomer('DEMO-CUST-012', '2026-09-29 11:00', InteractionType::Note, InteractionDirection::Outbound, InteractionOutcome::Neutral, 'Account note: payment terms discussion', 'Customer asked to move from Net 15 to Net 30.');
        $this->interact('L14', '2026-09-29 14:00', InteractionType::Call, InteractionDirection::Outbound, InteractionOutcome::Neutral, 'Check-in call after renovation estimate', 'Works delayed until Q1 next year.');
        $this->disqualify('L14', '2026-09-29 14:20', LeadDisqualificationReason::Timing, 'Renovation delayed; revisit in Q1.');
        $this->quotationRequestStartReview('QR-4', '2026-09-30 09:00', 'DEMO-CUST-010', [['P010', 'A2', 12, 'Shade matching for the new dentist']], 'Urgent restock for a new dentist.');
        $this->campaign('D', 'Q4 Implant Promotion', CampaignChannel::Email, 'crm.campaign.demo.q4_promo', '2026-09-30 10:00', '2026-10-08 10:00');
        $this->campaignRecipients('D', '2026-09-30 10:30', ['DEMO-CUST-001', 'DEMO-CUST-002', 'DEMO-CUST-003', 'DEMO-CUST-004', 'DEMO-CUST-005', 'DEMO-CUST-006', 'DEMO-CUST-007', 'DEMO-CUST-008', 'DEMO-CUST-009', 'DEMO-CUST-010'], []);
        $this->cancelCampaign('F', '2026-09-30 15:00');
        $this->interact('L04', '2026-09-30 11:00', InteractionType::Call, InteractionDirection::Inbound, InteractionOutcome::FollowUp, 'Asked for a revised proposal with training', 'Board meets next Tuesday.');
        $this->stage('OPP-F', '2026-09-30 15:30', OpportunityStage::Negotiation);
        $this->lead('L11', '2026-10-01 09:00');
        $this->campaign('E', 'Year-End Webinar Invitation', CampaignChannel::Email, 'crm.campaign.demo.webinar', '2026-10-01 11:00');
        $this->interactCustomer('DEMO-CUST-005', '2026-10-02 10:00', InteractionType::Call, InteractionDirection::Inbound, InteractionOutcome::FollowUp, 'Customer asked about the resin sample', 'Sample is being prepared.');
    }

    // ------------------------------------------------------------------ leads

    private function lead(string $key, string $at): void
    {
        $this->context->at($at);
        $crm = $this->context->as('crm_manager');
        [$source, $detail, $firstName, $lastName, $company, $title, $email, $phone, $assignee] = self::Leads[$key];

        $this->leads[$key] = app(LeadService::class)->create(new LeadData(
            source: $source,
            sourceDetail: $detail,
            firstName: $firstName,
            lastName: $lastName,
            companyName: $company,
            jobTitle: $title,
            email: $email,
            phone: $phone,
            preferredLanguage: 'en',
            assignedTo: $assignee === null ? null : DemoContext::keyOf($this->employeeUser($assignee)),
        ), $crm);
    }

    private function interact(
        string $key,
        string $at,
        InteractionType $type,
        InteractionDirection $direction,
        ?InteractionOutcome $outcome,
        string $summary,
        ?string $notes,
        ?LeadStatus $advance = null,
    ): void {
        $moment = $this->context->at($at);
        $actor = $this->context->as('crm_manager');
        $lead = $this->leads[$key]->refresh();

        $this->lastInteraction[$key] = app(LeadService::class)->logAndAdvance(
            $lead,
            new InteractionData($lead, $type, $direction, $moment, $summary, $outcome, $notes),
            $advance,
            $actor,
        );
    }

    private function interactCustomer(string $code, string $at, InteractionType $type, InteractionDirection $direction, ?InteractionOutcome $outcome, string $summary, ?string $notes): void
    {
        $moment = $this->context->at($at);
        $actor = $this->context->as('crm_manager');

        app(InteractionService::class)->log(
            new InteractionData($this->customer($code), $type, $direction, $moment, $summary, $outcome, $notes),
            $actor,
        );
    }

    private function disqualify(string $key, string $at, LeadDisqualificationReason $reason, string $note): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');

        app(LeadService::class)->disqualify($this->leads[$key]->refresh(), $reason, $this->lastInteraction[$key], $actor, $note);
    }

    private function convert(string $key, string $at): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');
        $conversion = self::Conversions[$key];
        $lead = $this->leads[$key]->refresh();

        $customer = app(LeadConversionService::class)->convert($lead, [
            'name' => $lead->first_name.' '.$lead->last_name,
            'username' => $conversion['username'],
            'email' => $conversion['login'],
            'password' => DemoContext::Password,
            'contact_is_self' => true,
            'company_name' => $conversion['company'],
            'company_email' => $conversion['email'],
            'company_phone' => $conversion['phone'],
            'address' => $conversion['address'],
            'country' => 'AE',
            'city' => $conversion['city'],
            'latitude' => $conversion['lat'],
            'longitude' => $conversion['lng'],
        ], $actor);

        // The provisioner's own code scheme is CUST-####; align with the demo numbering (direct write, no side effects).
        $customer->forceFill(['customer_code' => $conversion['code']])->saveQuietly();
    }

    // ----------------------------------------------------------- opportunities

    private function opportunity(
        string $key,
        string $at,
        string $title,
        string $summary,
        int $valueMinor,
        string $closeDate,
        int $probability,
        string $ownerNumber,
        ?string $leadKey = null,
        ?string $customerCode = null,
    ): void {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');

        $this->opportunities[$key] = app(OpportunityService::class)->create(new OpportunityData(
            summary: $summary,
            customerId: $customerCode === null ? null : DemoContext::keyOf($this->customer($customerCode)),
            leadId: $leadKey === null ? null : DemoContext::keyOf($this->leads[$leadKey]),
            title: $title,
            estimatedValueMinor: $valueMinor,
            currency: 'AED',
            expectedCloseDate: $closeDate,
            probabilityPercent: $probability,
            ownerId: DemoContext::keyOf($this->employeeUser($ownerNumber)),
            origin: OpportunityOrigin::Manual,
        ), $actor);
    }

    private function stage(string $key, string $at, OpportunityStage $to, ?OpportunityCloseReason $reason = null, ?string $note = null): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');

        $this->opportunities[$key] = app(OpportunityService::class)->transitionStage(
            $this->opportunities[$key]->refresh(),
            $to,
            null,
            $actor,
            $reason,
            $note,
        );
    }

    // ---------------------------------------------------------------- campaigns

    private function seedCampaignTemplates(): void
    {
        $templates = [
            ['crm.campaign.demo.newsletter', NotificationChannel::Mail, 'Autumn news for {{ recipient_name }}', '{{ campaign_name }}: new arrivals in resins, implant fixtures and sterilisation supplies.'],
            ['crm.campaign.demo.resin_promo', NotificationChannel::Mail, 'Resin offer for {{ recipient_name }}', '{{ campaign_name }}: 8% off surgical guide and model resin until 30 September.'],
            ['crm.campaign.demo.flash_sms', NotificationChannel::Sms, null, '{{ campaign_name }} - 10% off maintenance kits this week only.'],
            ['crm.campaign.demo.q4_promo', NotificationChannel::Mail, 'Q4 implant pricing for {{ recipient_name }}', '{{ campaign_name }}: tiered pricing on implant fixtures from 8 October.'],
            ['crm.campaign.demo.webinar', NotificationChannel::Mail, 'You are invited, {{ recipient_name }}', '{{ campaign_name }}: join our year-end product and workflow webinar.'],
            ['crm.campaign.demo.expo_followup', NotificationChannel::Mail, 'Thank you for visiting, {{ recipient_name }}', '{{ campaign_name }}: a summary of what we showed at the expo.'],
        ];

        foreach ($templates as [$key, $channel, $subject, $body]) {
            NotificationTemplate::query()->firstOrCreate(
                ['key' => $key, 'locale' => 'en', 'channel' => $channel],
                ['subject' => $subject, 'body' => $body, 'variables' => ['recipient_name', 'campaign_name'], 'is_active' => true],
            );
        }
    }

    private function campaign(string $key, string $name, CampaignChannel $channel, string $templateKey, string $at, ?string $scheduledAt = null): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');
        $template = NotificationTemplate::query()->where('key', $templateKey)->where('locale', 'en')->firstOrFail();

        $this->campaigns[$key] = app(CampaignService::class)->create(new CampaignData(
            name: $name,
            channel: $channel,
            contentTemplateId: DemoContext::keyOf($template),
            scheduledAt: $scheduledAt === null ? null : Carbon::parse($scheduledAt, config()->string('app.timezone')),
        ), $actor);
    }

    /**
     * @param  list<string>  $customerCodes
     * @param  list<string>  $leadKeys
     */
    private function campaignRecipients(string $key, string $at, array $customerCodes, array $leadKeys): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');

        $this->campaigns[$key] = app(CampaignService::class)->buildRecipients($this->campaigns[$key]->refresh(), [
            'include_leads' => $leadKeys !== [],
            'include_customers' => $customerCodes !== [],
            'lead_ids' => array_map(fn (string $leadKey): int => DemoContext::keyOf($this->leads[$leadKey]), $leadKeys),
            'customer_ids' => array_map(fn (string $code): int => DemoContext::keyOf($this->customer($code)), $customerCodes),
        ], $actor);
    }

    private function campaignDispatch(string $key, string $at): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');

        $this->campaigns[$key] = app(CampaignDispatchService::class)->dispatch($this->campaigns[$key]->refresh(), $actor);
    }

    private function cancelCampaign(string $key, string $at): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');

        $this->campaigns[$key] = app(CampaignService::class)->cancel($this->campaigns[$key]->refresh(), $actor);
    }

    private function campaignResponse(string $key, string $recipientRef, string $at, CampaignResponseType $type, ?string $notes = null): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');

        $recipient = str_starts_with($recipientRef, 'L')
            ? $this->leads[$recipientRef]
            : $this->customer($recipientRef);

        $row = CampaignRecipient::query()
            ->where('campaign_id', $this->campaigns[$key]->getKey())
            ->where('recipient_type', $recipient->getMorphClass())
            ->where('recipient_id', $recipient->getKey())
            ->firstOrFail();

        app(CampaignResponseService::class)->record($row, $type, $notes === null ? [] : ['notes' => $notes], $actor);
    }

    /** The inbound opportunity raised by the campaign B interest response moves on after the sample request. */
    private function advanceInboundSampleOpportunity(): void
    {
        $this->context->at('2026-09-24 16:00');
        $actor = $this->context->as('crm_manager');
        $inbound = SalesOpportunity::query()->where('origin', OpportunityOrigin::Inbound->value)->orderBy('id')->get();

        $sample = $inbound->firstWhere('customer_id', $this->customer('DEMO-CUST-005')->getKey());

        if ($sample instanceof SalesOpportunity && $sample->stage === OpportunityStage::Qualification) {
            app(OpportunityService::class)->transitionStage($sample, OpportunityStage::NeedsAnalysis, null, $actor);
        }
    }

    // ------------------------------------------------- customers and requests

    private function customerOnboarding(string $code, string $at): void
    {
        $this->context->at($at);
        $meta = self::Onboarding[$code];
        $number = mb_substr($code, -3);

        app(CustomerAccountProvisioningService::class)->provision(
            [
                'name' => $meta['company'],
                'username' => 'demo-cust-'.$number,
                'email' => "demo.cust{$number}@ierp.test",
                'password' => DemoContext::Password,
            ],
            [
                'customer_code' => $code,
                'company_name' => $meta['company'],
                'email' => "accounts.cust{$number}@clinic.test",
                'phone' => "+97150700{$number}",
                'address' => "Building {$number}, {$meta['city']}",
                'country' => 'AE',
                'city' => $meta['city'],
                'latitude' => $meta['lat'],
                'longitude' => $meta['lng'],
                'contact_is_self' => true,
                'is_active' => false,
            ],
            [],
            CustomerProvisioningSource::JoinUs,
        );
    }

    private function customerOnboardingDecision(string $code, string $at, string $decision): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');
        $customer = $this->customer($code);
        $approvals = app(CustomerApprovalService::class);

        match ($decision) {
            'changes' => $approvals->requestChanges($actor, $customer, 'The trade licence copy is unreadable. Please upload a clear scan.'),
            'reject' => $approvals->reject($actor, $customer, 'The licence could not be verified with the licensing authority.'),
            default => throw new LogicException("Unknown onboarding decision [{$decision}]."),
        };
    }

    /** @param array<string, string> $changes */
    private function changeRequest(string $at, string $code, array $changes, string $reason, ?string $decision, ?string $decidedAt, ?string $decisionReason = null): void
    {
        $this->context->at($at);
        $customer = $this->customer($code);
        $service = app(CustomerProfileChangeRequestService::class);

        $request = $service->create($customer, $changes, [], $customer->user, $reason);

        if ($decision === null || $decidedAt === null) {
            return;
        }

        $this->context->at($decidedAt);
        $actor = $this->context->as('crm_manager');

        match ($decision) {
            'approve' => $service->approve($actor, $request, 'Checked against the signed contract.'),
            'reject' => $service->reject($actor, $request, (string) $decisionReason),
            'cancel' => $service->cancel($actor, $request),
            default => throw new LogicException("Unknown change request decision [{$decision}]."),
        };
    }

    /** @var array<string, CustomerQuotationRequest> */
    private array $quotationRequests = [];

    /** @param list<array{0: string, 1: string, 2: int, 3: ?string}> $lines */
    private function quotationRequest(string $key, string $at, string $code, array $lines, ?string $notes): void
    {
        $this->context->at($at);

        $this->quotationRequests[$key] = app(CustomerQuotationRequestService::class)->submit(
            $this->customer($code),
            array_map(fn (array $line): array => [
                'product_variant_id' => DemoContext::keyOf($this->variant($line[0], $line[1])),
                'requested_quantity' => $line[2],
                'customer_note' => $line[3],
            ], $lines),
            null,
            $notes,
        );
    }

    /** @param list<array{0: string, 1: string, 2: int, 3: ?string}> $lines */
    private function quotationRequestStartReview(string $key, string $at, string $code, array $lines, ?string $notes): void
    {
        $this->quotationRequest($key, $at, $code, $lines, $notes);
        $this->context->at('2026-09-30 09:40');
        $actor = $this->context->as('crm_manager');
        $this->quotationRequests[$key] = app(CustomerQuotationRequestService::class)->startReview($actor, $this->quotationRequests[$key]->refresh());
    }

    private function quotationRequestReview(string $key, string $at): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');
        $this->quotationRequests[$key] = app(CustomerQuotationRequestService::class)->startReview($actor, $this->quotationRequests[$key]->refresh());
    }

    private function quotationRequestConvert(string $key, string $at): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');
        app(CustomerQuotationRequestService::class)->convertToQuotation($actor, $this->quotationRequests[$key]->refresh());
    }

    private function quotationRequestReject(string $key, string $at, string $reason): void
    {
        $this->context->at($at);
        $actor = $this->context->as('crm_manager');
        app(CustomerQuotationRequestService::class)->reject($actor, $this->quotationRequests[$key]->refresh(), $reason);
    }

    // ----------------------------------------------------------------- lookups

    private function customer(string $code): CustomerProfile
    {
        return CustomerProfile::query()->where('customer_code', $code)->firstOrFail();
    }

    private function employeeUser(string $number): User
    {
        return User::query()->where('email', "demo.emp{$number}@ierp.test")->firstOrFail();
    }

    private function variant(string $product, string $suffix): ProductVariant
    {
        return ProductVariant::query()->where('sku', DemoMasterDataSeeder::sku($product, $suffix))->firstOrFail();
    }
}
