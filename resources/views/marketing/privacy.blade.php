<x-legal.page
    title="Privacy Policy"
    description="What personal data the hosted Bilis service collects, why, where it is stored, and what rights you have."
>
    <x-slot:summary>
        @php
            $where = config('legal.hosting.in_eea')
                ? 'Everything stays in the EU, on servers in '.config('legal.hosting.country').'.'
                : 'Everything is stored on servers in '.config('legal.hosting.country').', covered by an EU adequacy decision.';
        @endphp

        <p>{{ $where }} We use essential cookies only — no analytics, no trackers, no third-party scripts or fonts. We are the controller for your account details and the processor for whatever your applications write into their logs. We never sell your data or train models on it.</p>
    </x-slot:summary>

    <h2 id="who">1. Who is responsible</h2>

    <p>{{ config('legal.operator.name') }}, {{ config('legal.operator.address') }}, {{ config('legal.operator.country') }} (company ID {{ config('legal.operator.company_id') }}) is the data controller for the personal data described in section 3. You can reach us about anything in this document at <a href="mailto:{{ config('legal.contact.privacy') }}">{{ config('legal.contact.privacy') }}</a>.</p>

    <p>We have not appointed a Data Protection Officer, because we do not meet the criteria in Article 37 of the GDPR. The address above reaches the people who actually handle this.</p>

    <h2 id="two-kinds">2. Two different kinds of data</h2>

    <p>This distinction runs through the whole policy, so it is worth getting straight first.</p>

    <table>
        <tr>
            <th>Account data</th>
            <td><strong>We are the controller.</strong> Your name, email, team membership, billing records, and the technical data we need to run the service. We decide what to collect and why. Sections 3 to 9 cover this.</td>
        </tr>
        <tr>
            <th>Log data</th>
            <td><strong>We are the processor; you are the controller.</strong> Whatever your applications send to the ingest endpoint. We do not decide what goes in it and we do not use it for our own purposes. Section 10 covers this.</td>
        </tr>
    </table>

    <h2 id="what-we-collect">3. What we collect, and why</h2>

    <h3>3.1 Account and profile</h3>

    <p>When you register we store your <strong>name</strong>, <strong>email address</strong>, and a <strong>password hash</strong> (we never store the password itself). If you enable two-factor authentication we store the secret and your recovery codes; if you register a passkey we store its public key and credential identifier. We record whether and when you verified your email.</p>

    <p><em>Why:</em> to create and secure your account. <em>Legal basis:</em> performance of a contract (Art. 6(1)(b)) and, for the security features, our legitimate interest in protecting accounts (Art. 6(1)(f)).</p>

    <h3>3.2 Teams and invitations</h3>

    <p>We store which teams you belong to and your role in each. When someone invites a colleague, we store the <strong>invited email address</strong>, the role offered, who sent it, and when it expires — and we send that person an email.</p>

    <p><em>Why:</em> to make shared access work. <em>Legal basis:</em> contract, and our legitimate interest in letting customers collaborate.</p>

    <h3>3.3 Projects and API keys</h3>

    <p>We store your project names and, for each API key, the name you gave it, a <strong>short non-secret prefix</strong>, a <strong>SHA-256 hash of the key</strong>, and the time it was last used. The key itself is shown once and never stored — we cannot recover it for you or for anyone else.</p>

    <p><em>Why:</em> to authenticate ingest and let you see which keys are live. <em>Legal basis:</em> contract.</p>

    <h3>3.4 Session and technical data</h3>

    <p>While you are signed in we store a session record containing your <strong>IP address</strong>, <strong>browser user agent</strong>, and last activity time. Our servers also keep standard access logs (IP address, timestamp, URL, response status, user agent) for a short period.</p>

    <p><em>Why:</em> to keep you signed in, to show you your active sessions, and to detect and investigate abuse and outages. <em>Legal basis:</em> contract and legitimate interest in the security and reliability of the service.</p>

    <h3>3.5 Billing</h3>

    <p>Paid plans are sold through {{ config('legal.payments.merchant_of_record') }} Managed Payments, which makes {{ config('legal.payments.merchant_of_record') }} the merchant of record. You enter your billing details and payment method with {{ config('legal.payments.merchant_of_record') }}, not with us. <strong>We never see or store your card number.</strong></p>

    <p>What reaches us is the transaction record: your billing name and country, the tax identifier you gave at checkout where applicable, the plan, the amounts, and whether payment succeeded. {{ config('legal.payments.merchant_of_record') }} is the controller for the payment data it holds under its own <a href="https://stripe.com/privacy" rel="noopener noreferrer" target="_blank">privacy policy</a>, and it issues your receipts and invoices directly.</p>

    <p><em>Why:</em> to charge you and to satisfy tax and accounting law. <em>Legal basis:</em> contract and legal obligation (Art. 6(1)(c)).</p>

    <h3>3.6 Correspondence</h3>

    <p>If you email us, we keep the email and our reply.</p>

    <p><em>Why:</em> to answer you and to have a record of what was agreed. <em>Legal basis:</em> legitimate interest in supporting our users.</p>

    <h3>3.7 What we do not collect</h3>

    <p>No advertising identifiers. No behavioural profiling. No analytics or product-usage telemetry. No session recording or heatmaps. No data brokers. No automated decision-making that produces legal or similarly significant effects on anyone.</p>

    <h2 id="cookies">4. Cookies</h2>

    <p><strong>Bilis uses strictly necessary cookies only.</strong> There is no analytics cookie, no advertising cookie, and nothing that requires consent — which is why you do not see a cookie banner.</p>

    <table>
        <tr>
            <th>Cookie</th>
            <th>Purpose</th>
            <th>Lifetime</th>
        </tr>
        <tr>
            <td>Session cookie</td>
            <td>Keeps you signed in and carries the CSRF token that protects forms</td>
            <td>{{ (int) (config('session.lifetime') / 60) }} hours of inactivity</td>
        </tr>
        <tr>
            <td>Remember-me cookie</td>
            <td>Set only if you ask to stay signed in</td>
            <td>Until you sign out</td>
        </tr>
        <tr>
            <td>Appearance preference</td>
            <td>Remembers whether you chose light or dark mode</td>
            <td>1 year</td>
        </tr>
    </table>

    <p>Cookies are set with the <code>HttpOnly</code> and <code>SameSite=Lax</code> flags, and over HTTPS only.</p>

    <h2 id="third-parties">5. Third-party content</h2>

    <p>The Bilis interface loads <strong>no third-party resources at runtime</strong>. Fonts are downloaded when we build the application and served from our own servers, so your browser never contacts a font CDN. There are no embedded analytics scripts, no tag managers, no social widgets, and no external images. Visiting bilis.app does not reveal your IP address to anyone but us and our hosting provider.</p>

    <h2 id="sub-processors">6. Who else touches the data</h2>

    <p>We do not sell personal data and we do not share it for anyone else's marketing. We share it only with the service providers below, each bound by a written data processing agreement.</p>

    <table>
        <tr>
            <th>Provider</th>
            <th>What for</th>
            <th>Where</th>
        </tr>
        @foreach (config('legal.sub_processors') as $processor)
            <tr>
                <td>
                    @if ($processor['url'])
                        <a href="{{ $processor['url'] }}" rel="noopener noreferrer" target="_blank">{{ $processor['name'] }}</a>
                    @else
                        {{ $processor['name'] }}
                    @endif
                </td>
                <td>{{ $processor['purpose'] }}</td>
                <td>{{ $processor['location'] }}</td>
            </tr>
        @endforeach
    </table>

    <p>We give customers at least 30 days' notice by email before we add or replace a sub-processor. See section 6.5 of the <a href="{{ route('terms') }}#data-processing">Terms of Service</a> for your right to object.</p>

    <p>We may also disclose data where the law compels it — a valid court order or a lawful request from a public authority. We will tell you unless we are legally forbidden from doing so, and we will push back on requests that look overbroad. We may also share data with a professional adviser under confidentiality, or with a successor if the business is sold, in which case you will be told beforehand.</p>

    <h2 id="location">7. Where your data lives</h2>

    @php
        $located = 'is stored on servers operated by '.config('legal.hosting.provider').' in '.config('legal.hosting.country')
            .(config('legal.hosting.in_eea') ? ', inside the European Union.' : '.');
    @endphp

    <p><strong>All data — account data and log data alike — {{ $located }}</strong></p>

    @if (config('legal.hosting.in_eea'))
        <p>We do not transfer personal data outside the EU or EEA. If that ever needs to change, we will update this policy and put a valid Chapter V transfer mechanism, such as the European Commission's Standard Contractual Clauses, in place before any transfer happens.</p>
    @else
        <p>{{ \Illuminate\Support\Str::ucfirst(config('legal.hosting.country')) }} is outside the EEA, so storing your data there is a transfer under Chapter V of the GDPR. It is covered by {{ config('legal.hosting.transfer_basis') }}, which means the transfer needs no Standard Contractual Clauses and no transfer impact assessment: the European Commission has found the destination to offer a level of protection essentially equivalent to EU law.</p>

        <p>If that finding is withdrawn or lapses, we will put a valid Chapter V mechanism — Standard Contractual Clauses or another ground — in place before the transfer continues, and update this policy.</p>
    @endif

    <p>We use no other hosting region and no third-country sub-processor for log data.</p>

    <h2 id="retention">8. How long we keep things</h2>

    <table>
        <tr>
            <th>Data</th>
            <th>Kept for</th>
        </tr>
        <tr>
            <td>Log records you ingest</td>
            <td>{{ config('legal.log_retention_days') }} days from ingest, then deleted automatically</td>
        </tr>
        <tr>
            <td>Account and profile</td>
            <td>Until you delete your account, then up to {{ config('legal.account_deletion_grace_days') }} days</td>
        </tr>
        <tr>
            <td>Backups</td>
            <td>{{ config('legal.backup_retention_days') }} days, after which deletions propagate</td>
        </tr>
        <tr>
            <td>Sessions</td>
            <td>Until expiry or sign-out</td>
        </tr>
        <tr>
            <td>Server access logs</td>
            <td>Up to 90 days</td>
        </tr>
        <tr>
            <td>Unaccepted team invitations</td>
            <td>Until they expire, then deleted</td>
        </tr>
        <tr>
            <td>Invoices and accounting records</td>
            <td>As long as tax law requires — typically 10 years</td>
        </tr>
        <tr>
            <td>Support correspondence</td>
            <td>3 years from the last message</td>
        </tr>
    </table>

    <h2 id="rights">9. Your rights</h2>

    <p>Under the GDPR you have the right to:</p>

    <ul>
        <li><strong>Access</strong> — get a copy of the personal data we hold about you.</li>
        <li><strong>Rectification</strong> — have inaccurate data corrected. Most of it you can edit yourself in the app.</li>
        <li><strong>Erasure</strong> — have your data deleted, subject to records we must keep by law.</li>
        <li><strong>Restriction</strong> — have us pause processing while a dispute is resolved.</li>
        <li><strong>Portability</strong> — receive your data in a structured, machine-readable format.</li>
        <li><strong>Object</strong> — object to processing based on legitimate interests, on grounds relating to your situation.</li>
        <li><strong>Withdraw consent</strong> — where we rely on consent, withdraw it at any time, without affecting what came before.</li>
    </ul>

    <p>Email <a href="mailto:{{ config('legal.contact.privacy') }}">{{ config('legal.contact.privacy') }}</a> and we will respond within one month. There is no charge unless a request is manifestly unfounded or excessive.</p>

    <p>If you think we have got something wrong, please tell us first — but you always have the right to complain to a supervisory authority, either where you live or where we are established. Ours is the {{ config('legal.jurisdiction.supervisory_authority') }} (<a href="{{ config('legal.jurisdiction.supervisory_authority_url') }}" rel="noopener noreferrer" target="_blank">{{ str_replace('https://', '', config('legal.jurisdiction.supervisory_authority_url')) }}</a>).</p>

    <h2 id="log-data">10. If your data is in someone else's logs</h2>

    <p>If a company uses Bilis to store its application logs, and your personal data appears in those logs, <strong>that company is the controller, not us</strong>. We host the data on their instruction and have no independent right to use it.</p>

    <p>Please direct any request — access, erasure, anything else — to that company. If you contact us instead and we can identify the customer concerned, we will forward your request and tell you we have done so, but we cannot act on your data ourselves without their instruction. We assist our customers in responding, as our processor obligations require.</p>

    <h2 id="security">11. Security</h2>

    <p>Data is encrypted in transit with TLS and encrypted at rest. Passwords are hashed with a modern password-hashing function; API keys are stored only as SHA-256 hashes. Access to production systems is restricted to the people who need it and protected by multi-factor authentication. Every project's data is isolated at the query layer, and all database queries are parameterised.</p>

    <p>If a personal data breach affects you, we will notify the supervisory authority within 72 hours where the GDPR requires it, and tell affected users without undue delay where the risk to them is high. Our vulnerability disclosure policy is at <a href="{{ config('bilis.github_url') }}/blob/main/SECURITY.md">SECURITY.md</a>.</p>

    <h2 id="children">12. Children</h2>

    <p>Bilis is a tool for people who run software in production. It is not directed at children, and we do not knowingly collect data from anyone under 16. If you believe a child has given us personal data, email us and we will delete it.</p>

    <h2 id="self-hosted">13. Self-hosted instances</h2>

    <p>This policy covers <strong>bilis.app</strong> only. If you use a copy of Bilis that someone else runs on their own servers, that operator is the controller and their privacy policy applies — we have no access to their instance and no visibility into it.</p>

    <h2 id="changes">14. Changes to this policy</h2>

    <p>We will update this page when our practices change. For material changes we will email the address on your account at least 30 days before they take effect, and update the date at the top. Past versions are visible in the <a href="{{ config('bilis.github_url') }}/commits/main/resources/views/marketing/privacy.blade.php">public repository history</a> — you can see exactly what changed and when.</p>

    <h2 id="contact">15. Contact</h2>

    <p>Privacy questions: <a href="mailto:{{ config('legal.contact.privacy') }}">{{ config('legal.contact.privacy') }}</a><br>
    Security reports: <a href="mailto:{{ config('legal.contact.security') }}">{{ config('legal.contact.security') }}</a><br>
    Everything else: <a href="mailto:{{ config('legal.contact.general') }}">{{ config('legal.contact.general') }}</a></p>

    <p>Postal: {{ config('legal.operator.name') }}, {{ config('legal.operator.address') }}, {{ config('legal.operator.country') }}.</p>
</x-legal.page>
