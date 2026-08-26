<x-legal.page
    title="Terms of Service"
    description="The terms that govern use of the hosted Bilis service at bilis.app."
>
    <x-slot:summary>
        <p>You keep ownership of your logs. We store them in the EU, keep them for {{ config('legal.log_retention_days') }} days, and do not read, sell, or train on them. You pay for what you use, you can leave whenever you like, and our liability is capped at what you paid us. Self-hosting Bilis is governed by the software licence instead — these terms are only about the hosted service.</p>
    </x-slot:summary>

    <h2 id="who-we-are">1. Who we are and what this covers</h2>

    <p>The hosted Bilis service at <strong>bilis.app</strong> is operated by {{ config('legal.operator.name') }}, {{ config('legal.operator.address') }}, {{ config('legal.operator.country') }} (company ID {{ config('legal.operator.company_id') }}, tax ID {{ config('legal.operator.tax_id') }}@if (config('legal.operator.vat_id')), VAT {{ config('legal.operator.vat_id') }}@endif), registered in {{ config('legal.operator.register') }}. In this document, <strong>“we”</strong>, <strong>“us”</strong> and <strong>“Bilis”</strong> mean that entity, and <strong>“you”</strong> means the person or organisation using the service.</p>

    <p>These terms form a contract between you and us. They apply when you create an account, send data to the service, or use it in any other way. If you are agreeing on behalf of a company, you confirm you are allowed to bind it.</p>

    <p><strong>These terms cover the hosted service only.</strong> The Bilis source code is separately licensed under the <a href="{{ config('bilis.github_url') }}/blob/main/LICENSE.md">Functional Source License</a>. If you run Bilis on your own infrastructure, that licence governs and this document does not apply to you — see section 15.</p>

    <h2 id="the-service">2. The service</h2>

    <p>Bilis accepts log records over an HTTP ingest endpoint, stores them, and gives you a web interface to search, filter, and live-tail them. That is the whole product. We may add or change features over time; if we remove something you rely on, we will give you reasonable notice and, where a paid feature disappears mid-term, a pro-rata refund.</p>

    <p>The service is provided over the public internet. It depends on your network, your log shippers, and your own configuration, none of which we control.</p>

    <h2 id="accounts">3. Accounts, teams, and API keys</h2>

    <p>You need an account to use the service. Give us accurate details and keep them current. You are responsible for everything that happens under your account, including actions by people you invite to your team.</p>

    <p><strong>API keys are credentials.</strong> A key grants the ability to write logs into a specific project. We show a key once at creation and store only a hash of it — we cannot recover a lost key, only issue you a new one. Treat keys like passwords: keep them out of source control, rotate them if exposed, and revoke them when a service is decommissioned. Anything ingested with your key is treated as yours.</p>

    <p>Team owners can add and remove members and can see all data in the team's projects. If you invite someone, you are vouching for their access.</p>

    <p>You must be at least 16 years old, or the age of digital consent where you live, whichever is higher.</p>

    <h2 id="acceptable-use">4. Acceptable use</h2>

    <p>Use the service for storing and searching your own application logs. Do not:</p>

    <ul>
        <li>send data you have no right to send, or that you are contractually or legally barred from putting on third-party infrastructure;</li>
        <li>deliberately ingest special categories of personal data (health, biometrics, political or religious views, sexual orientation), payment card numbers, or government identifiers — see section 6.4;</li>
        <li>use the service to store anything that is not a log record, such as backups, media libraries, or file storage;</li>
        <li>attempt to access another customer's data, probe or load-test the service without written permission, or interfere with its operation;</li>
        <li>resell the hosted service, or use it to provide a substantially similar log-search product to third parties;</li>
        <li>break the law with it, or use it to store material that is illegal where we operate.</li>
    </ul>

    <p>We do not proactively read your logs. But if we receive a credible report of abuse, or if an operational problem forces us to look, we may inspect the minimum necessary to investigate.</p>

    <h2 id="your-data">5. Your data</h2>

    <p><strong>You own your logs.</strong> Sending data to Bilis grants us a narrow, revocable licence to host, store, transmit, index, and display it — only to the extent needed to run the service for you, keep it secure, and comply with the law. Nothing more.</p>

    <p>To be explicit about what we do not do: we do not sell your data, we do not share it with advertisers, and we do not use it to train machine learning models — ours or anyone else's.</p>

    <p>You can export or delete your data at any time while your account is open. On termination, see section 11.</p>

    <h2 id="data-processing">6. Data processing terms</h2>

    <p>This section is our data processing agreement under Article 28 of the GDPR. It applies automatically — you do not need to sign a separate document, though we will sign our standard DPA on request.</p>

    <h3>6.1 Roles</h3>

    <p>For the personal data contained in the logs you send us, <strong>you are the controller and we are the processor</strong>. You decide what to log, why, and for how long; we only act on your instructions.</p>

    <p>For your account data — the names and email addresses of your team members, billing records, and our own service logs — <strong>we are the controller</strong>. Our <a href="{{ route('privacy') }}">Privacy Policy</a> explains that part.</p>

    <h3>6.2 Scope of processing</h3>

    <table>
        <tr>
            <th>Subject matter</th>
            <td>Storage, indexing, search, and display of log records</td>
        </tr>
        <tr>
            <th>Duration</th>
            <td>For as long as your account is open, subject to the retention period in section 6.6</td>
        </tr>
        <tr>
            <th>Nature and purpose</th>
            <td>Providing the hosted log storage and search service</td>
        </tr>
        <tr>
            <th>Types of personal data</th>
            <td>Whatever your applications write into their logs. Typically: IP addresses, user and account identifiers, email addresses, request paths, user agents, and free-text message bodies</td>
        </tr>
        <tr>
            <th>Categories of data subject</th>
            <td>Your users, your employees, and anyone else whose data appears in your application logs</td>
        </tr>
    </table>

    <h3>6.3 Our obligations</h3>

    <p>We will:</p>

    <ul>
        <li>process personal data only on your documented instructions — using the service is an instruction — unless EU or member state law requires otherwise, in which case we will tell you first unless the law forbids it;</li>
        <li>ensure that everyone authorised to process the data is bound by confidentiality;</li>
        <li>implement the technical and organisational measures described in section 7;</li>
        <li>assist you, taking into account the nature of processing, with data subject requests, data protection impact assessments, and prior consultations;</li>
        <li>notify you without undue delay, and in any case within 48 hours, after becoming aware of a personal data breach affecting your data, with the information you need for your own Article 33 notification;</li>
        <li>on termination, delete your data in line with section 11 and this section, unless law requires us to keep it;</li>
        <li>make available the information you need to demonstrate compliance, and allow audits under section 6.7.</li>
    </ul>

    <h3>6.4 Your obligations</h3>

    <p>You are responsible for what you log. Specifically, you warrant that you have a lawful basis for the personal data your applications send us, that you have given the required privacy notices to the people it concerns, and that your instructions to us do not breach data protection law.</p>

    <p><strong>Bilis is not designed for special category data.</strong> It is a general-purpose log store with no field-level encryption, no per-field access control, and no redaction pipeline. Do not send health data, biometrics, or payment card numbers, and configure your log shippers to strip them. If you send them anyway, you do so at your own risk and remain the controller for the consequences.</p>

    <h3>6.5 Sub-processors</h3>

    <p>You give us general authorisation to engage sub-processors. The current list is published in our <a href="{{ route('privacy') }}#sub-processors">Privacy Policy</a>. We will give you at least 30 days' notice by email before adding or replacing one. If you reasonably object on data protection grounds within that window and we cannot resolve it, you may terminate the affected part of the service and get a pro-rata refund.</p>

    <p>Every sub-processor is bound by written terms no less protective than these, and we remain fully liable to you for their performance.</p>

    <h3>6.6 Location, transfers, and retention</h3>

    <p><strong>Your log data does not leave the European Union.</strong> It is stored on servers operated by OVH in France. We do not transfer it to third countries. If that ever changes, we will give you 30 days' notice and put Standard Contractual Clauses or another Chapter V mechanism in place first.</p>

    <p>Log records are retained for <strong>{{ config('legal.log_retention_days') }} days</strong> from ingest and then deleted automatically. Backups are retained for a further {{ config('legal.backup_retention_days') }} days.</p>

    <h3>6.7 Audits</h3>

    <p>On reasonable written notice, and no more than once a year unless a regulator or a breach requires otherwise, we will answer a written security questionnaire and provide the documentation we hold. An on-site audit is available where the GDPR requires it, at your cost, subject to confidentiality and to not disrupting other customers.</p>

    <h2 id="security">7. Security</h2>

    <p>We take the measures appropriate to the risk under Article 32 of the GDPR, including: encryption in transit (TLS) and at rest; API keys stored only as salted hashes and never recoverable; strict per-project data isolation enforced at the query layer; parameterised database queries throughout; access to production limited to the people who need it and protected by multi-factor authentication; and regular dependency patching.</p>

    <p>Our full vulnerability disclosure policy is at <a href="{{ config('bilis.github_url') }}/blob/main/SECURITY.md">SECURITY.md</a>. Report anything you find to <a href="mailto:{{ config('legal.contact.security') }}">{{ config('legal.contact.security') }}</a>.</p>

    <p>No system is perfectly secure. Your side of this is real too: protect your API keys, control who you invite to your team, and do not log secrets.</p>

    <h2 id="fees">8. Plans, fees, and payment</h2>

    <p>Prices and plan limits are published on the site and may change. Where you are on a paid plan, we will give you at least 30 days' notice before a price increase takes effect, and you may cancel before it does.</p>

    <p>Paid plans are billed in advance for the period you choose and, unless you cancel, renew automatically for the same period.</p>

    <h3>8.1 Who you pay</h3>

    <p>We sell the hosted service through <strong>{{ config('legal.payments.merchant_of_record') }} Managed Payments</strong>. That means {{ config('legal.payments.merchant_of_record') }} is the <strong>merchant of record</strong> for your purchase — you buy from {{ config('legal.payments.merchant_of_record') }} rather than from {{ config('legal.operator.name') }} directly. In practice:</p>

    <ul>
        <li>Checkout, payment processing, and the payment relationship are handled by {{ config('legal.payments.merchant_of_record') }}. Your purchase appears as <em>“Sold through {{ config('legal.payments.customer_facing_brand') }}”</em>, and your card or bank statement shows <strong>{{ config('legal.payments.statement_descriptor_prefix') }}</strong> followed by our name.</li>
        <li>Fees are stated exclusive of sales tax, VAT, and GST. {{ config('legal.payments.merchant_of_record') }} calculates and adds the tax due for your location at checkout, and files and remits it to the relevant tax authority. Where {{ config('legal.payments.merchant_of_record') }} does not cover a country, we remain responsible for the tax in that country.</li>
        <li>Receipts, invoices, credit notes, and refund notifications are sent to you by {{ config('legal.payments.merchant_of_record') }} directly, not by us.</li>
        <li>You can view your order history, update your payment method and billing address, and cancel or change a subscription at <a href="{{ config('legal.payments.order_management_url') }}" rel="noopener noreferrer" target="_blank">{{ str_replace('https://', '', config('legal.payments.order_management_url')) }}</a>, as well as in your Bilis account.</li>
    </ul>

    <p>Your contract for the <em>service itself</em> — everything else in these terms — remains with us.</p>

    <h3>8.2 Refunds, failed payments, and disputes</h3>

    <p>If a payment fails, we will tell you and give you a reasonable chance to fix it before suspending the account. Genuinely unused, prepaid time is refundable on request; consumed time is not. This does not affect any statutory right of withdrawal you have as a consumer.</p>

    <p>You can request a refund from us, or from <a href="{{ config('legal.payments.support_url') }}" rel="noopener noreferrer" target="_blank">{{ config('legal.payments.customer_facing_brand') }} support</a>. Because {{ config('legal.payments.merchant_of_record') }} is the merchant of record, it handles payment and subscription support and may issue a refund within 60 days of a transaction, including without our approval where we do not respond to it in time. It also manages card disputes and chargebacks on our behalf. Refunds include any tax you paid.</p>

    <p>During any free tier, trial, or beta period, we may change or withdraw the offer with reasonable notice.</p>

    <h2 id="availability">9. Availability and support</h2>

    <p>We aim to keep the service running continuously and to give advance notice of planned maintenance. <strong>We do not currently offer a contractual uptime commitment or service credits.</strong> If we introduce a service level agreement for a plan, its terms will be published and will take precedence over this section for that plan.</p>

    <p>Ingest is designed to accept data best-effort: malformed records are skipped rather than rejected, and a successful response means your data has been queued, not that it has been durably written. Bilis is a log store, not a system of record. <strong>Do not use it as the only copy of anything you cannot lose.</strong></p>

    <p>Support is by email at <a href="mailto:{{ config('legal.contact.general') }}">{{ config('legal.contact.general') }}</a> during business days.</p>

    <h2 id="suspension">10. Suspension</h2>

    <p>We may suspend your account or a project, in whole or in part, if you materially breach these terms, if your usage threatens the stability or security of the service or other customers, if payment is overdue after notice, or if the law requires it.</p>

    <p>Except where the problem is urgent or legally compelled, we will warn you first and give you a chance to fix it. We will restore access as soon as the cause is resolved.</p>

    <h2 id="termination">11. Termination and what happens to your data</h2>

    <p>You may close your account at any time from the app. We may terminate for material breach that you do not fix within 30 days of notice, or terminate a free account for any reason on 30 days' notice.</p>

    <p>On termination, your log data becomes inaccessible immediately and is deleted from live systems within <strong>{{ config('legal.account_deletion_grace_days') }} days</strong>, and from backups within a further {{ config('legal.backup_retention_days') }} days. Export anything you want to keep before you close the account — after the grace period we cannot recover it, and that is deliberate.</p>

    <p>We keep the minimum records the law requires us to keep, such as invoices for tax purposes.</p>

    <h2 id="warranties">12. Warranties and disclaimers</h2>

    <p>We warrant that we will provide the service with reasonable skill and care, and that we have the right to enter into this agreement.</p>

    <p><strong>Beyond that, and to the fullest extent permitted by law, the service is provided “as is” and “as available”, without warranties of any kind, express or implied, including implied warranties of merchantability, fitness for a particular purpose, title, non-infringement, or that the service will be uninterrupted, error-free, or that data will never be lost.</strong></p>

    <p>Nothing in these terms excludes liability that cannot lawfully be excluded, including liability for death or personal injury caused by negligence, for fraud or fraudulent misrepresentation, for gross negligence or wilful misconduct, or any statutory rights you have as a consumer.</p>

    <h2 id="liability">13. Limitation of liability</h2>

    <p>Subject to section 12, and to the fullest extent permitted by law:</p>

    <ul>
        <li><strong>Neither party is liable for indirect or consequential loss</strong>, including lost profits, lost revenue, lost business, lost goodwill, or the cost of substitute services.</li>
        <li><strong>Our total aggregate liability</strong> arising out of or relating to this agreement in any 12-month period <strong>is capped at the greater of (a) the fees you paid us in the 12 months before the event giving rise to the claim, or (b) EUR 100.</strong></li>
        <li>We are not liable for loss or corruption of data to the extent it results from your configuration, your log shippers, your failure to maintain your own copies, or from you sending us data you should not have sent.</li>
    </ul>

    <p>The cap does not apply to your obligation to pay fees, to either party's breach of confidentiality, or to your indemnity under section 14.</p>

    <p>These limits reflect the price of the service. If you need a higher cap, talk to us — that is a commercial conversation, not something we can offer by default at these prices.</p>

    <h2 id="indemnity">14. Indemnity</h2>

    <p>You will indemnify us against third-party claims, and reasonable legal costs, arising from data you sent to the service that you had no right to send, from your breach of section 4 or 6.4, or from your use of the service in breach of the law. We will tell you promptly about any such claim, let you control the defence, and cooperate reasonably.</p>

    <h2 id="self-hosting">15. Self-hosting and the source code</h2>

    <p>The Bilis source code is published under the Functional Source License (FSL-1.1-ALv2). That licence lets you run Bilis for your own internal use, for non-commercial education and research, and in connection with professional services you provide to someone else running it — and stops you offering Bilis to others as a competing commercial product or hosted service. Each release additionally becomes available under the Apache License 2.0 two years after we publish it.</p>

    <p>If you self-host, your relationship with the software is governed entirely by that licence, including its warranty disclaimer. We provide no support, no security guarantees, and no liability for a self-hosted instance, and this agreement does not apply to it.</p>

    <h2 id="confidentiality">16. Confidentiality</h2>

    <p>Each party may learn non-public information about the other. Both will keep it confidential, use it only to perform this agreement, and protect it with at least reasonable care. This does not cover information that is public through no fault of the receiving party, was already known to it, or is independently developed. Disclosure compelled by law is allowed, with notice to the other party where lawful.</p>

    <h2 id="changes">17. Changes to these terms</h2>

    <p>We may update these terms. For material changes we will email the address on your account at least <strong>30 days</strong> before they take effect. Continuing to use the service after that means you accept the new terms; if you do not, close your account before the effective date and we will refund any unused prepaid time.</p>

    <p>Minor corrections — typos, clarifications, updated contact details — take effect when published.</p>

    <h2 id="law">18. Governing law and disputes</h2>

    <p>These terms are governed by the law of {{ config('legal.jurisdiction.governing_law') }}, without regard to its conflict of law rules. Disputes go to {{ config('legal.jurisdiction.courts') }}.</p>

    <p>If you are a consumer resident in the EU, this does not deprive you of the protection of the mandatory law of your own country, and you may bring proceedings in your local courts. The European Commission's online dispute resolution platform is at <a href="https://ec.europa.eu/consumers/odr" rel="noopener noreferrer" target="_blank">ec.europa.eu/consumers/odr</a>.</p>

    <p>Before going to court, please email us — most things are faster to fix that way.</p>

    <h2 id="general">19. General</h2>

    <ul>
        <li><strong>Entire agreement.</strong> These terms, the Privacy Policy, and any order form you sign are the whole agreement between us on this subject.</li>
        <li><strong>Severability.</strong> If a provision is unenforceable, the rest stands and the provision is read down to the minimum change needed to make it enforceable.</li>
        <li><strong>No waiver.</strong> Not enforcing something once does not waive it.</li>
        <li><strong>Assignment.</strong> You may not assign this agreement without our written consent, except to a successor of your business. We may assign it to a successor of ours, on notice to you.</li>
        <li><strong>Notices.</strong> We will reach you at the email address on your account — keep it current. You can reach us at <a href="mailto:{{ config('legal.contact.general') }}">{{ config('legal.contact.general') }}</a>.</li>
        <li><strong>Force majeure.</strong> Neither party is liable for delay caused by events genuinely outside its reasonable control.</li>
        <li><strong>No third-party rights.</strong> Nobody other than you and us can enforce these terms.</li>
        <li><strong>Language.</strong> The English version governs.</li>
    </ul>
</x-legal.page>
