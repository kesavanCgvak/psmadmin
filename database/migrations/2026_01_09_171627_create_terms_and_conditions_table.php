<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('terms_and_conditions', function (Blueprint $table) {
            $table->id();
            $table->longText('description');
            $table->timestamps();
        });

        // Insert initial terms and conditions
        $initialTerms = "YOU UNDERSTAND THAT BY USING THE SITE OR SITE SERVICES OF SECOND WAREHOUSE, INC. dba \"Pro Subrental Marketplace\" (PSM) AFTER THE EFFECTIVE DATE (DEFINED AS THE DATE AND TIME YOU ARE FIRST REGISTERED AS A USER), YOU AGREE TO BE BOUND BY THESE TERMS OF SERVICE. IF YOU DO NOT ACCEPT THE TERMS OF SERVICE IN ITS ENTIRETY, YOU ARE NOT ALLOWED, AND MUST CONSEQUENTLY NOT ACCESS OR USE, THE SITE OR THE SITE SERVICES. IF YOU AGREE TO THE TERMS OF SERVICE ON BEHALF OF AN ENTITY, OR IN CONNECTION WITH PROVIDING OR RECEIVING SERVICES ON BEHALF OF AN ENTITY OR AGENCY, YOU REPRESENT AND WARRANT THAT YOU HAVE THE AUTHORITY TO BIND THAT ENTITY OR AGENCY TO THE TERMS OF SERVICE. IN THAT EVENT, \"YOU\",\"YOUR\", AND \"USER\" WILL REFER AND APPLY TO THAT ENTITY OR AGENCY.
1 Aim and purpose of terms and conditions - registration
The aim of Pro Subrental Marketplace is to provide services aimed at enhancing the efficiency of item rental between entities. A reliable source of items available for rent or sub-rent can enhance the services offered by these entities to their clients and to provide an opportunity for entities to promote their own inventory for sub-rental.
All companies wishing to use the Pro Subrental Marketplace service must register and provide the information requested on the registration page.
By registering as a Pro Subrental Marketplace user, You confirm to have read, understood, and agree to be bound by the following terms and conditions and further to act in accordance with any and all guidelines, information etc. as may be published by Pro Subrental Marketplace from time to time. PSM reserves the right to amend, modify, or replace these Terms of Service from time to time in its sole and absolute discretion. Such modifications will be communicated to You in writing or via this website, and continued use of the services after notification to You will constitute your consent to be bound by and to comply with such modifications. If You object to the modified Terms of Service, your sole recourse will be to cease usage of the site or site services. PSM reserves the right and ability to terminate a user at any time for any reason or no reason at all, in its sole and absolute discretion.
2 Monitoring of all traffic
In order to provide the best possible service, all activity on the Pro Subrental Marketplace site and usage of site services is monitored and logged by PSM.
3 Availability of equipment and cancellations
Any equipment appearing as available in the PSM database is considered available for sub-rent to any other Pro Subrental Marketplace user. Suppliers wishing to make certain equipment unavailable for a particular period must take the necessary steps to remove that equipment temporarily from the database by marking it as reserved in the PSM database. It is the sole responsibility of the supplier to keep his inventory status updated at all times.
It is against the spirit and purpose of the Pro Subrental Marketplace site and services for suppliers to reject requests for equipment and for renters to cancel transactions following making a request for equipment, although it is understood that this may occur from time to time on a reasonable basis. Users rejecting equipment requests or cancelling transactions may be asked by PSM to provide a reason for the rejection or cancellation. Should a user repeatedly reject equipment requests or cancel transactions without reasonable cause, the user risks having their Pro Subrental Marketplace star rating lowered and possible exclusion from the Pro Subrental Marketplace service (at the sole and reasonable discretion of PSM).
In the case of exclusion from the service, the user will remain listed on the Pro Subrental Marketplace users page for a period of time, marked as having been removed from the service for failing to comply with Pro Subrental Marketplace terms and conditions.
4 Limitation of liability
PSM is not liable, and you agree not to hold us responsible, for any damages or losses arising out of or in connection with the services provided or facilitated by PSM, including, but not limited to:
malfunctions, errors, delays, disruptions and similar circumstances related to the services provided by PSM;
degradation of standing or exclusion from the service as a result a user failing (in PSM sole opinion) to adhere to these terms and conditions, cf. Clause 3 above; Incorrect content and description of any equipment
In addition to the aforesaid, the user explicitly accepts and understands that PSM undertakes no responsibility whatsoever with regard to any act or omission, agreement or arrangement related to the users and/or its counterparts, including, for the avoidance of doubt, the content and description of the equipment, non-payment, damage to or loss of equipment.
PSM does neither have any liability for any loss or damages arising from subsequent cancellation or modification of previously confirmed equipment requests made between parties through the service, neither shall PSM be involved in any disputes between parties arising from such matters or otherwise.
PSM recommends that the parties enter into a separate agreement with regard to the equipment to be rented and to also consult its advisors in that respect. PSM is not responsible for any disputes between users and You agree to release PSM from any and all demands, claims, or obligations for any dispute You may have with a fellow user of the PSM site or site services.
You agree that your use of this site and the site services shall be at your sole risk. To the fullest extent permitted by law, PSM and its officers, directors, employees, parents, affiliates, shareholders, representatives, and agents disclaim all warranties, express or implied, in connection with the PSM site and your use thereof. PSM makes no warranties or representations about the content of any sites linked to this site and assumes no responsibility or liability for (i) any errors, mistakes, or inaccuracies of content; (ii) any unauthorized access to or use of our servers and/or any and all personal information or financial information stored therein; (iii) any bugs, viruses, trojan horses, or the like which may be transmitted to or through our website by any third-party. PSM does not warrant, endorse, guarantee, or assume responsibility for any product or service advertised or offered by a third-party through the site and PSM will not be a party or in any way responsible for monitoring any transaction between You and third-party providers or users of products or services.
PSM shall not be liable for any failures, delays, outages, or any other performance issues caused by circumstances beyond its reasonable control, including without limitation, strikes, labor disputes, fires, accidents, actions of any governmental authorities, emergency declarations, pandemics and epidemics, acts of God, war or insurrection, or power or utility outages or disruptions.
If, irrespectively of the aforesaid, PSM should be held liable to pay compensation to You, PSM's cumulative liability on any basis in shall be limited to the total payments made by You to PSM during the twelve (12) months prior to the date the relevant loss is suffered. You hereby release PSM from all obligations, liability, claims, or demands in excess of this limitation.
5 Transport
PSM will not be held responsible for any loss or damage caused to equipment while in transit or for any loss or damage caused by late deliveries and all users confirm that PSM is mutually indemnified for such damages.
6 Insurance
It is the sole responsibility of suppliers to insure their equipment against loss or damage while being hired or sub-hired. PSM does not take responsibility for any loss or damage caused to equipment hired or sub-hired via the service. Any disputes arising from such damage or loss must be handled directly between the party hiring or sub-hiring the equipment and the supplier. PSM will not become involved in any disputes arising from such eventualities and all users confirm that they indemnify and hold harmless PSM from any and all claims.
7 Billing
All users will be billed in accordance with current price regulations. Late payment may result in degradation of a supplier's Pro Subrental Marketplace standing. Non-payment will result in expulsion from the service and legal action, in addition to late payment interests as stated on the bill.
8 Copyright and data security
Users agree that they will not use the Pro Subrental Marketplace site to display copyrighted or offensive material, or to upload any material or content which may be harmful to the operation of the Pro Subrental Marketplace service. PSM reserves the right to remove any content it considers to be harmful, derogatory to another party, or against the spirit of the Pro Subrental Marketplace service.
PSM agrees to treat all user information, other than that intended for public viewing on the Pro Subrental Marketplace service, as strictly confidential. PSM agrees to take every possible precaution to protect user information collected through the use of the service.
PSM takes every possible precaution to protect the service against malicious data attacks or breaches and users are urged to be vigilant regarding online security.
PSM accepts no responsibility for loss or damage occurring as a result of improperly secured, lost or stolen log-in data. Prior indemnification clauses will apply.
9 Miscellaneous
PSM reserves its right to delete marketing or posts that in its sole option and discretion believes violates any applicable law, regulation, or these Terms of Service. PSM further reserves the ability to delete (partly or in whole) any marketing or posts that, in the opinion of PSM, are incorrect, misleading, offensive, or otherwise not in line with the guidelines, information etc. as published by PSM from time to time.
Each user of the Pro Subrental Marketplace site is independent from PSM. These users are not owners, employees, or agents of PSM, and PSM is not responsible for the representations or actions of these suppliers. Usage of this site or site services shall not constitute or be construed to be or create a partnership, agency, joint venture, or other similar relationship between the user and PSM. The supplier has the sole responsibility of the marketing of its equipment and PSM shall not be liable in any regard for the Supplier's marketing, including in the event any marketing made by the Supplier is inaccurate, has been deleted by mistake or is caused by misunderstanding. PSM provides no warranty and is not responsible for the acts or conduct of any person or entity (whether supplier or renter) using this site or the site services.
The unauthorized use of any trademarks or logos of PSM, including those displayed on this site, is strictly prohibited. By using this site and site services, You agree that you will not use this site or site services for any purpose that is unlawful or in contravention of these Terms of Service, including pirating, data copying, or infiltration.
10 Confidentiality
PSM will not sell, share or pass on user information to any third party. The only user information made available by PSM to a third party will be that provided by the user themselves for public viewing via the Pro Subrental Marketplace service.
11 Legal venue and choice of law
Any and all disputes arising from this agreement in any way - directly or indirectly - involving and or regarding a claim towards PSM shall be settled in accordance with the laws of the State of Delaware in the USA and through the State of Delaware court system.";

        DB::table('terms_and_conditions')->insert([
            'description' => $initialTerms,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms_and_conditions');
    }
};
