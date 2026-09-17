<?php

namespace Database\Seeders;

use App\Models\ChatbotKnowledge;
use Illuminate\Database\Seeder;

class ChatbotKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        // Remove superseded titles from earlier short seed sets.
        ChatbotKnowledge::query()
            ->whereIn('title', [
                'Supply job offers',
                'Company inventory sync',
                'Support requests',
            ])
            ->delete();

        $entries = [
            // General
            [
                'title' => 'What is Pro Subrental Marketplace',
                'category' => 'General',
                'question' => 'What is PSM / Pro Subrental Marketplace?',
                'content' => 'Pro Subrental Marketplace (PSM), operated by Second Warehouse, Inc., helps rental companies request and supply equipment for sub-rental. Companies register, list or find gear, send rental requests to other companies, and negotiate jobs through offers and handshakes. PSM connects companies and facilitates the workflow; rental agreements, insurance, and payment for the gear itself remain between the companies.',
                'keywords' => 'psm, pro subrental marketplace, about, overview, what is, marketplace',
                'sort_order' => 10,
            ],
            [
                'title' => 'What PSM does and does not do',
                'category' => 'General',
                'question' => 'Is PSM responsible for the rental equipment or disputes?',
                'content' => 'PSM provides the marketplace tools and matching workflow. Suppliers are responsible for keeping inventory availability accurate. PSM is not liable for equipment condition, transit damage, insurance, or disputes between companies. Confirmed deals are business between renter and provider—see the Terms of Service for full details.',
                'keywords' => 'liability, disputes, insurance, terms, responsibility',
                'sort_order' => 20,
            ],

            // Accounts
            [
                'title' => 'Provider vs user (renter) accounts',
                'category' => 'Accounts',
                'question' => 'What is the difference between a provider and a user account?',
                'content' => 'At registration you choose provider or user. Providers list marketplace inventory and respond to rental demand as supply jobs. Users (renters) request equipment from providers and manage rental jobs. Both types belong to a company. Providers typically get a longer Stripe trial and inventory-based trial incentives; users get a shorter trial.',
                'keywords' => 'provider, renter, user, account type, role',
                'sort_order' => 30,
            ],
            [
                'title' => 'Company admins and company users',
                'category' => 'Accounts',
                'question' => 'What can company admins and company users do?',
                'content' => 'Each company can have multiple users with roles admin or user. Company admins can invite users, update company users, and promote others to admin. The first registering user becomes the company admin and default contact. There is a company-wide user limit configured by PSM.',
                'keywords' => 'admin, company user, invite, make admin, user limit',
                'sort_order' => 40,
            ],

            // Getting started
            [
                'title' => 'How to register',
                'category' => 'Getting started',
                'question' => 'How do I register for PSM?',
                'content' => 'Register with account type, company name, username, name, region/country/state/city, birthday, email, mobile, password, and acceptance of Terms. You can check username and company-name availability before submitting. When payments are enabled, a Stripe payment method and billing details are required at signup. After registering, verify your email before you can log in.',
                'keywords' => 'register, signup, create account, availability, verify email',
                'sort_order' => 50,
            ],
            [
                'title' => 'Login and email verification',
                'category' => 'Getting started',
                'question' => 'Why can’t I log in after registering?',
                'content' => 'You must verify your email using the link PSM sends before login works. Use Forgot Password if you need a reset link emailed to your profile email. Provider company users may also be blocked from login if the company subscription is expired or canceled when payment is required.',
                'keywords' => 'login, verify email, password reset, forgot password, subscription blocked',
                'sort_order' => 60,
            ],
            [
                'title' => 'Profile and company setup',
                'category' => 'Getting started',
                'question' => 'How do I update my profile and company details?',
                'content' => 'After login you can update profile fields (name, username, email, mobile), upload a profile picture, and change your password. Company settings cover company info, address, images/logo, default contact, and preferences such as currency, date format, pricing scheme, and rental software. Providers can also set Gear Finder visibility and promotional logo consent.',
                'keywords' => 'profile, company settings, preferences, logo, default contact',
                'sort_order' => 70,
            ],

            // Inventory / Catalog
            [
                'title' => 'Shared catalog vs my company inventory',
                'category' => 'Inventory',
                'question' => 'What is the difference between the product catalog and my equipment inventory?',
                'content' => 'The shared catalog (inventory master) holds standard products with brand, category, model, and a PSM Code. Your company inventory is your company’s stock of those products—quantity, rental price, software code, images, and marketplace physical details. Rentals match against catalog products; providers fulfill from their company inventory.',
                'keywords' => 'inventory master, company inventory, equipment, catalog, psm code',
                'sort_order' => 80,
            ],
            [
                'title' => 'Adding equipment to my inventory',
                'category' => 'Inventory',
                'question' => 'How do I add equipment to my company inventory?',
                'content' => 'Search the catalog, then create or attach a product to your company inventory with quantity and pricing. You can update quantity, price, software code, description, marketplace details, and images. You can also import via Excel import sessions (upload, analyze matches, confirm) or sync from Flex/Rentman when connected.',
                'keywords' => 'add equipment, create or attach, import, quantity, rental price',
                'sort_order' => 90,
            ],
            [
                'title' => 'Brands, categories, and products',
                'category' => 'Catalog',
                'question' => 'How are brands, categories, and products organized?',
                'content' => 'Products belong to a brand, category, and optional subcategory, and each has a model name and PSM Code. You can browse brands/categories while signed in and search products by keyword (model, description, or PSM Code). If a needed product is missing, create-or-attach can add it to the catalog and link it to your inventory when allowed.',
                'keywords' => 'brand, category, subcategory, product, psm code, search',
                'sort_order' => 100,
            ],

            // Jobs
            [
                'title' => 'Creating a rental request',
                'category' => 'Jobs',
                'question' => 'How do I create a rental request?',
                'content' => 'A rental request creates a rental job with name, start/end dates, shipping method, optional delivery address, and equipment lines grouped by provider company. You can mark items as open to similar/equivalent products, add a global message, private messages per provider, and an optional initial offer. Each selected provider gets a supply job and is notified by email.',
                'keywords' => 'rental request, rental job, create request, similar products',
                'sort_order' => 110,
            ],
            [
                'title' => 'Rental jobs vs supply jobs',
                'category' => 'Jobs',
                'question' => 'What is a rental job versus a supply job?',
                'content' => 'A rental job is the renter’s overall request. A supply job is that request as seen by one provider company. Providers update supply quantities and milestone dates (packing, delivery, return, unpacking), send offers, and later complete the job. Renters can update job basics/quantities and cancel the rental job when needed.',
                'keywords' => 'rental job, supply job, provider, milestones',
                'sort_order' => 120,
            ],
            [
                'title' => 'Offers, negotiation, and handshake',
                'category' => 'Jobs',
                'question' => 'How do offers and handshakes work?',
                'content' => 'Either side can send priced offers on a job, but you cannot send two offers in a row—wait for the other party. Handshake accepts the other party’s pending offer and confirms the supply job; you cannot handshake your own offer. Canceling negotiation is only available to the receiver of the latest pending offer. After handshake, other open supply jobs for remaining demand may be affected as quantities are fulfilled.',
                'keywords' => 'offer, handshake, negotiate, accept offer, cancel negotiation',
                'sort_order' => 130,
            ],
            [
                'title' => 'Shipping and delivery options',
                'category' => 'Jobs',
                'question' => 'What shipping methods can I choose on a rental request?',
                'content' => 'Requests support: I will pick it up, You deliver to me, and You ship to Job Site. Pickup does not require a delivery address; the other methods do. Default is “You deliver to me” if none is specified. PSM does not handle transport risk—loss or delay in transit is between the companies under the Terms.',
                'keywords' => 'shipping, delivery, pickup, job site, address',
                'sort_order' => 140,
            ],

            // Collaboration / Trust
            [
                'title' => 'Job comments',
                'category' => 'Collaboration',
                'question' => 'Can I message the other company on a job?',
                'content' => 'Yes. Comments are available on supply jobs for the renter on that rental job and the provider company. You can view, add, edit, and delete comments to coordinate details during negotiation and fulfillment.',
                'keywords' => 'comments, messages, supply job chat',
                'sort_order' => 150,
            ],
            [
                'title' => 'Ratings and reviews',
                'category' => 'Trust',
                'question' => 'How do ratings work?',
                'content' => 'You can rate other companies (1–5 stars; not your own). After jobs, renters can rate providers (or skip), and providers can rate renters (or skip). Company reviews combine job ratings and company ratings. Average ratings appear on company profiles. Repeated unreasonable cancellations/rejections may affect standing under the Terms.',
                'keywords' => 'rating, review, stars, rate provider, rate renter',
                'sort_order' => 160,
            ],
            [
                'title' => 'Blocking companies or providers',
                'category' => 'Trust',
                'question' => 'How do I block a company?',
                'content' => 'You can block another company so it is filtered from your interactions, and unblock later. There is also a company-level provider block that blocks a provider for your whole company. You cannot block your own company.',
                'keywords' => 'block, unblock, provider block, hide company',
                'sort_order' => 170,
            ],

            // Integrations
            [
                'title' => 'Flex integration',
                'category' => 'Integrations',
                'question' => 'How does Flex work with PSM?',
                'content' => 'Providers can save Flex API credentials, then search Flex inventory, check import status, import items into company inventory, or link Flex resources to existing PSM inventory. From marketplace inventory you can also search and confirm Flex sync for a product. Connected Flex jobs may sync sales-quote details on rental/supply jobs when configured.',
                'keywords' => 'flex, integration, sync, import inventory',
                'sort_order' => 180,
            ],
            [
                'title' => 'Rentman integration',
                'category' => 'Integrations',
                'question' => 'How does Rentman work with PSM?',
                'content' => 'Providers can connect Rentman with an API key, sync equipment into a local cache, search that cache, import into company inventory, or link Rentman items to existing inventory. Marketplace inventory can search and confirm Rentman sync. Companies typically maintain one primary inventory integration type with Flex or Rentman credentials.',
                'keywords' => 'rentman, integration, sync, import equipment',
                'sort_order' => 190,
            ],
            [
                'title' => 'HireTrack integration',
                'category' => 'Integrations',
                'question' => 'What does HireTrack do in PSM?',
                'content' => 'If your company’s rental software preference is HireTrack, new rental requests can email you a HireTrack-ready import text file of software codes and quantities. Items without a HireTrack software code on your company inventory are listed as skipped for manual review. HireTrack is driven by that software preference and codes—not a separate Flex/Rentman-style API connection screen.',
                'keywords' => 'hiretrack, software code, import txt, rental software',
                'sort_order' => 200,
            ],
            [
                'title' => 'Provider Open API and API keys',
                'category' => 'Integrations',
                'question' => 'Can I access my inventory via API?',
                'content' => 'Provider companies can request Open API access (a PSM admin must enable it), then generate a key (psm_pk_…), reveal, or revoke keys. With a key, partners can search/list/get product inventory scoped to that provider. Keys are shown once at creation—store them securely. Non-providers cannot manage these keys.',
                'keywords' => 'open api, partner api, api key, psm_pk, provider api',
                'sort_order' => 210,
            ],

            // Billing
            [
                'title' => 'Subscriptions and billing',
                'category' => 'Billing',
                'question' => 'How does PSM billing work?',
                'content' => 'Paid plans are billed through Stripe. Signed-in users can view the current subscription, update the payment method, cancel (usually at period end), and download invoices from billing history. When payment is enabled, a card is collected at registration. Canceling a company subscription affects all users in that company. Provider plans and user plans have different monthly amounts configured in PSM.',
                'keywords' => 'subscription, stripe, billing, invoice, payment method, cancel',
                'sort_order' => 220,
            ],
            [
                'title' => 'Free trials and provider inventory bonuses',
                'category' => 'Billing',
                'question' => 'Do I get a free trial?',
                'content' => 'Yes when payment is enabled: providers typically start with about 60 days (roughly 2 months) and users about 14 days. Providers can earn extra free months by adding qualified marketplace inventory (items with a product and rental price), at milestones such as 75, 125, 175, 225, and 275 products. Progress is available in the trial-incentive / subscription screens.',
                'keywords' => 'trial, free months, incentive, inventory milestone, provider trial',
                'sort_order' => 230,
            ],

            // Support / Legal
            [
                'title' => 'Contact support',
                'category' => 'Support',
                'question' => 'How do I contact support?',
                'content' => 'From the app, submit a Support Request with your email, an issue type, and a description. Standard issue types include Subscription Issue, General Technical Support Issue, and Importing Products Issue. The request is emailed to the PSM support inbox. Before signup, you can also use Contact Sales.',
                'keywords' => 'support, help, ticket, issue type, contact sales',
                'sort_order' => 240,
            ],
            [
                'title' => 'CMS pages and Terms of Service',
                'category' => 'Legal',
                'question' => 'Where do I find Terms and other information pages?',
                'content' => 'Terms and Conditions are available from the public terms API and must be accepted at registration. Additional published content pages (About, Privacy, etc., depending on what admins publish) are available as CMS pages by slug. PSM may update Terms; continued use after notice means you accept the updates.',
                'keywords' => 'terms, conditions, cms, privacy, legal',
                'sort_order' => 250,
            ],
            [
                'title' => 'Chatbot help inside the app',
                'category' => 'Support',
                'question' => 'How do I get quick answers in the app?',
                'content' => 'Signed-in users can open the AI chatbot, start a conversation, and ask product questions. Answers are grounded in PSM’s published FAQ knowledge. For account-specific billing or technical failures, prefer a Support Request so the team can follow up by email.',
                'keywords' => 'chatbot, faq, help, ai assistant',
                'sort_order' => 260,
            ],

            // Referrals
            [
                'title' => 'Referral links and referred-by',
                'category' => 'Referrals',
                'question' => 'How do referrals work?',
                'content' => 'Signed-in companies can create or reuse a referral link/code that points new companies to registration (/register?ref=…). New registrants can enter a referral code or search and select a referring company. A referral code takes precedence over manual company selection. Public validation returns basic referring-company info for valid codes.',
                'keywords' => 'referral, refer a company, referral code, referred by',
                'sort_order' => 270,
            ],

            // How to / Troubleshooting
            [
                'title' => 'Find providers in Gear Finder',
                'category' => 'How to',
                'question' => 'How do I find other rental companies?',
                'content' => 'Use company search / Gear Finder to discover companies that have not hidden themselves from Gear Finder. Companies can adjust search priority and visibility. Blocked providers or companies may not appear in your normal flows.',
                'keywords' => 'gear finder, find company, search companies, visibility',
                'sort_order' => 280,
            ],
            [
                'title' => 'Import products from Excel',
                'category' => 'How to',
                'question' => 'How do I import a product list?',
                'content' => 'Start an import session, upload your Excel file, run analyze to match rows to the catalog (including PSM Code matching), adjust selections, then confirm. You can save draft selections, remove rows, reanalyze, or cancel the session. If import fails, submit a support request under Importing Products Issue.',
                'keywords' => 'import, excel, matching, draft, psm code',
                'sort_order' => 290,
            ],
            [
                'title' => 'Similar / equivalent products on a request',
                'category' => 'How to',
                'question' => 'What does “similar OK” mean on a rental request?',
                'content' => 'When you mark products (or a provider group) as open to similar items, providers are told you will consider equivalents. They should contact you if they can offer suitable alternatives rather than only the exact catalog product.',
                'keywords' => 'similar, equivalent, alternative, is similar',
                'sort_order' => 300,
            ],
            [
                'title' => 'Complete and rate a finished job',
                'category' => 'How to',
                'question' => 'How do I finish a supply job?',
                'content' => 'Providers mark a supply job complete when fulfillment is done. Afterward, both sides can submit ratings (or skip). Providers may also reply to ratings where supported. Completion reminders may be emailed if a job stays open past expected dates.',
                'keywords' => 'complete job, rate job, skip rating, finish',
                'sort_order' => 310,
            ],
            [
                'title' => 'Token expired / session refresh',
                'category' => 'Troubleshooting',
                'question' => 'What if my session expires?',
                'content' => 'The mobile/API app uses JWT authentication. If your session expires, use the refresh endpoint or sign in again. Profile and most company/job APIs require a valid token. Public endpoints like terms, CMS pages, product search, and registration checks do not.',
                'keywords' => 'session, token, refresh, logout, unauthorized',
                'sort_order' => 320,
            ],
        ];

        foreach ($entries as $entry) {
            ChatbotKnowledge::query()->updateOrCreate(
                ['title' => $entry['title']],
                [
                    ...$entry,
                    'is_active' => true,
                ],
            );
        }
    }
}
