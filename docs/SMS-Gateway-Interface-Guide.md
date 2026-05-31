# SMS Gateway Interface (PSR-Sms)

## A PHP Standards-Style Specification for Interoperable SMS Drivers

---

## 0. Front Matter

| Field | Value |
| --- | --- |
| **Title** | SMS Gateway Interface |
| **Proposed Namespace** | `Psr\Sms` |
| **Status** | Draft / Proposed Standard |
| **Target Runtime** | PHP 8.1+ (uses native types, readonly value objects, and backed enums) |
| **Editors' Lineage** | PSR-7 (immutable `with*()` messages), PSR-18 (single-method client + exception-interface hierarchy), Symfony Notifier (`supports()`) |
| **Companion Concrete Driver** | Mitake (三竹簡訊) HTTP API v2.14 — used as the worked reference mapping |

### 0.1 Goal

Define the **smallest mandatory contract** that *every* SMS gateway can honour — sending one message to one recipient — and a set of **segregated, capability-gated extension interfaces** that a driver implements *only if its provider supports the underlying behaviour*. The aim is a vendor-neutral seam against which library authors can write portable application code, while still reaching the long tail of providers (REST/JSON clouds, Taiwan/China key=value gateways, SMPP binaries, persistent-socket gateways).

### 0.2 Non-Goals

This specification deliberately does **NOT**:

- Mandate a transport (HTTP, SMPP PDU, TCP socket). Transport is a driver implementation detail.
- Define how credentials are stored, rotated, or injected.
- Provide a service locator, DI container binding, or framework integration.
- Standardise rich-channel (RCS/WhatsApp/Viber) interactive payloads beyond a capability flag and an optional fallback value object.
- Perform out-of-band regulatory registration (India DLT template approval, US 10DLC brand/campaign registration, China template+signature approval). Those are *state* and *out-of-band* operations; this spec only carries the **per-message compliance fields** that regulated routes require.
- Implement a scheduler for providers that lack native scheduling. A driver that does not advertise `Capability::SCHEDULE` simply does not implement `SchedulableSmsClientInterface`.

### 0.3 Why Single-Send Is the Only Core

A survey of ~50 gateways and standards (25 cloud/regional providers, 6 TW/CN providers, 11 standards/regulatory regimes, 4 PHP libraries) found exactly **one** operation that is genuinely universal: *send one message to one recipient*. Everything else — bulk, scheduling, balance, inbound, delivery polling, OTP — has real gaps on real providers. Following PSR-18's example (one method: `sendRequest()`), the core of this spec is one method: `send()`. Every other behaviour is an interface a driver opts into, discoverable at runtime via `CapabilityAwareInterface::supports()`.

---

## 1. Definitions & RFC 2119 Terminology

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**, **SHOULD NOT**, **RECOMMENDED**, **MAY**, and **OPTIONAL** in this document are to be interpreted as described in [RFC 2119](https://www.ietf.org/rfc/rfc2119.txt).

| Term | Definition |
| --- | --- |
| **Gateway / Provider** | A service that accepts a message and attempts delivery to a mobile subscriber. |
| **Driver / Adapter** | A PHP class implementing one or more interfaces in this spec, translating the canonical model to a provider's wire shape. |
| **MT** | Mobile-terminated message (platform → handset). The thing `send()` dispatches. |
| **MO** | Mobile-originated message (handset → platform). The thing `InboundMessageReceiverInterface` ingests. |
| **DLR** | Delivery Receipt — an asynchronous status update about a previously sent MT. |
| **Segment** | One on-air SMS unit. GSM-7 packs 160 chars (153 in a concatenated part); UCS-2 packs 70 (67 concatenated). Billing is per segment. |
| **Canonical state** | A `DeliveryState` enum case — the single normalisation target for every provider's status vocabulary. |
| **Capability** | A named, discoverable feature (a `Capability` enum case) advertised via `supports()`. |
| **Core interface** | An interface graded `core` — every conformant driver MUST implement it. |
| **Extension interface** | A segregated, capability-gated interface a driver implements only if the provider supports the behaviour. |
| **Conformant driver** | A class that implements at minimum `SmsClientInterface` and `CapabilityAwareInterface`, throwing only exceptions implementing `Psr\Sms\Exception\SmsExceptionInterface`. |

### 1.1 Interface-Segregation Principle (ISP) Restated

A caller **MUST NOT** be forced to depend on a method it does not use. Therefore: bulk, scheduling, balance, inbound, status query, receipt parsing, verification, and rich/async/compliance operations are each their **own** interface. A caller that only sends never types against anything but `SmsClientInterface`.

### 1.2 Immutability (PSR-7 spirit)

All message and value objects are **immutable**. Value objects use `readonly` promoted properties so the runtime itself enforces immutability after construction. Mutators are `with*()` methods that **MUST** return a *new instance* with the change applied and **MUST NOT** mutate the receiver. (Because `readonly` properties cannot be cloned-and-reassigned before PHP 8.3, each `with*()` constructs a fresh instance via `new self(...)` rather than `clone` + assignment.) Validation **MUST** occur in `__construct()` and in every `with*()`. Once constructed, an instance is safe to share and to reuse across multiple `send()` calls.

---

## 2. Use-Case Catalogue

Prevalence tiers: **universal** (essentially every send-capable gateway), **common** (most major gateways, real gaps), **niche** (a meaningful subset only).

| # | Use case | Tier | Home interface | Representative providers |
| --- | --- | --- | --- | --- |
| 1 | Single send (one recipient) | universal | `SmsClientInterface` (core) | All |
| 2 | Bulk / batch send | common | `BulkSmsClientInterface` | Twilio, MessageBird, Sinch, Mitake, Every8d, Alibaba, Tencent |
| 3 | Per-recipient personalisation | niche | `BulkSmsClientInterface` | MSG91, Sinch, Kavenegar |
| 4 | Client-side templated body | niche | `SmsClientInterface` + `Message` | SimpleSoftwareIO, Sinch, Infobip |
| 5 | Mandatory approved-template + signature | niche | `Message` (template XOR body) + `ComplianceFields` | Alibaba, Tencent |
| 6 | Fixed sender ID (alpha/long/short/toll-free) | universal | `Sender` | All |
| 7 | Sender pool / sticky / geomatch | common | `Sender::fromPool()` / `fromMessagingService()` | Twilio, Plivo, Telnyx, AWS EUM, MessageBird |
| 8 | Encoding (GSM-7 / UCS-2 / auto) | universal | `Encoding` | All |
| 9 | Long-message concatenation | universal | `SmsResult::getSegmentCount()` | All |
| 10 | Flash / class-0 | common | `Message::getMessageClass()` / `Capability::FLASH` | Vonage, MessageBird, Sinch, SMPP |
| 11 | Binary / UDH / WAP-push | niche | `BinaryContent` / `Capability::BINARY` | Vonage legacy, Sinch, Clickatell, SMPP |
| 12 | MMS / media (outbound MT) | common | `Message::getMediaUrls()` / `Capability::MMS` | Twilio, Telnyx, Plivo, Every8d |
| 12a | MMS / media (inbound MO) | common | `InboundMessageInterface::getMediaUrls()` / `Capability::INBOUND` | Twilio, Telnyx, Plivo |
| 13 | Scheduled / deferred send | common | `SchedulableSmsClientInterface` | Twilio, Mitake, Sinch, Infobip |
| 14 | Cancel / reschedule | niche | `SchedulableSmsClientInterface::cancel()` | Twilio, Sinch, Mitake, Every8d |
| 15 | Validity period / TTL | common | `ValidityPeriod` | Twilio, Vonage, AWS, Mitake |
| 16 | Delivery time-window | niche | `Message::getProviderOptions()` + compliance | Infobip, Karix, Tencent |
| 17 | Dry-run / cost estimate | niche | `Message::isDryRun()` / `Capability::DRY_RUN` | AWS EUM, Sinch, Infobip |
| 18 | Idempotency / correlation | common | `Message::getIdempotencyKey()` / `getClientRef()` | Twilio, Vonage, Mitake (clientid) |
| 19 | Max-price cap | niche | `Message::getMaxPrice()` / `Capability::MAX_PRICE` | Twilio, AWS |
| 20 | Message-type / route class | common | `MessageType` | AWS, Kaleyra, Plivo, Alibaba, Tencent |
| 21 | DLR via webhook | universal | `DeliveryReceiptParserInterface` | All major |
| 22 | DLR via poll | common | `DeliveryStatusQueryInterface` | Twilio, Mitake, Every8d, Tencent |
| 23 | Inline DLR/credit in send response | niche | `SmsResultInterface` | Vonage legacy, Mitake, Every8d |
| 24 | Status vocabulary normalisation | universal | `DeliveryState` | All |
| 25 | Delivery feedback / conversion | niche | `MessageFeedbackInterface` | AWS EUM, Sinch |
| 26 | Inbound MO reception | common | `InboundMessageReceiverInterface` | Twilio, Vonage, Plivo, SMPP |
| 27 | Two-way conversation threading | common | `InboundMessageInterface::getConversationId()` | Twilio, Vonage, Sinch |
| 28 | Inbound multipart reassembly | niche | `InboundMessageInterface` concat metadata | SMPP, Vonage |
| 29 | Provider-managed OTP | common | `VerificationInterface` | Twilio Verify, Vonage Verify v2, Infobip 2FA |
| 30 | Multi-channel OTP fallback | niche | `VerificationChannel` workflow | Twilio Verify, Vonage Verify v2 |
| 31 | Silent network auth | niche | `VerificationInterface` SNA channel | Twilio Verify, Vonage Verify v2 |
| 32 | Transaction-bound / PSD2 OTP | niche | `VerificationInterface` options | Twilio Verify |
| 33 | In-app token / AppHash | niche | `VerificationInterface` options | Termii, Twilio Verify |
| 34 | Self-managed raw OTP | common | `SmsClientInterface` (just `send()`) | Any send-only gateway, Mitake |
| 35 | Balance / credit query | common | `BalanceInterface` | Vonage legacy, Mitake, Every8d, Plivo |
| 36 | Per-message cost & segment reporting | common | `SmsResult` / `DeliveryStatus` `getPrice()`/`getSegmentCount()` | Twilio, Vonage, Plivo, Every8d |
| 37 | Aggregate usage statistics | niche | `CostReportInterface` | Tencent, Twilio, Infobip |
| 38 | Capability discovery | niche | `CapabilityAwareInterface` | (interop layer) |
| 39 | Pluggable auth / transport | niche | driver-internal | (interop layer) |
| 40 | Webhook signature verification | common | `SignatureVerifierInterface` | Twilio, Vonage, Plivo, Telnyx |
| 41 | E.164 / national / TON-NPI normalisation | universal | `PhoneNumber` | All |
| 42 | Opt-out / STOP-HELP | common | application state + `InboundMessage::getKeyword()` | AWS, Gupshup, Twilio |
| 43 | Consent capture | niche | application state | TCPA, GDPR |
| 44 | Brand + campaign registration | niche | `TemplateRegistryInterface` (out-of-band) | US 10DLC, Telnyx |
| 45 | Template/signature/sender-ID lifecycle | niche | `TemplateRegistryInterface` | India DLT, Alibaba, Tencent |
| 46 | Per-message regulatory fields | common | `ComplianceFieldsInterface` | India DLT, Vonage, AWS EUM |
| 47 | Country-aware sender selection | niche | application state | GDPR, India DLT |
| 48 | Multi-channel send + SMS fallback | niche | `ChannelFallback` VO / `Capability::MULTICHANNEL` | Twilio, Infobip OMNI |
| 49 | Rich interactive content | niche | `MultiChannelInterface` (out of scope v1) | RCS, WhatsApp |
| 50 | Channel reachability check | niche | (out of scope v1) | RCS, WhatsApp |
| 51 | Session-window billing | niche | (out of scope v1) | WhatsApp, Viber |
| 52 | Link shortening & tracking | niche | `Message::getProviderOptions()` | Twilio, MSG91, Karix |
| 53 | Content redaction / PII logging | niche | `RedactableInterface` / `Capability::REDACT` | Twilio, Plivo, AWS |
| 54 | Fraud / risk controls | niche | `Message::getProviderOptions()` | Twilio, Vonage |
| 55 | Queued / async dispatch | niche | `AsyncBulkInterface` / library concern | Laravel Vonage, Twilio Bulk |
| 56 | HLR / number lookup | niche | `NumberLookupInterface` / `Capability::HLR_LOOKUP` | MessageBird |
| 57 | Topic fan-out / pub-sub | niche | `TopicPublishInterface` | AWS SNS |
| 58 | Account preferences | niche | `AccountPreferencesInterface` | AWS SNS |
| 59 | Push-only event ingestion | niche | `EventDestinationParserInterface` | AWS EUM v2 |

---

## 3. The CORE Interface

There are exactly **two** core interfaces. Every conformant driver **MUST** implement both.

### 3.1 `SmsClientInterface` — grade: **core**

> **Purpose.** The one mandatory primitive: send ONE `Message` to ONE recipient. Modelled on PSR-18's single-method client. The bifurcated send model (free-form body XOR approved template+signature) is absorbed into `Message`, not split across two methods.

**Rules.**

1. A driver **MUST** implement `send()`.
2. `send()` **MUST** translate the canonical `MessageInterface` into the provider's wire shape, **MUST** normalise the recipient (see §10.2), and **SHOULD** forward a computed segment count where the provider accepts one.
3. `send()` **MUST** return an `SmsResultInterface`. The result **MUST** carry the provider message id when the provider returns one, and **MAY** carry inline status / price / segment / balance data (TW/legacy providers like Mitake and Every8d).
4. On caller input errors detectable before any network call, the driver **MUST** throw an exception implementing `InvalidArgumentExceptionInterface`.
5. On transport failure with no usable response, the driver **MUST** throw `NetworkExceptionInterface`. On rejected credentials, `AuthenticationExceptionInterface`. All other failures **MUST** throw something implementing `SmsExceptionInterface`.
6. A driver **MUST NOT** throw any exception that does not implement `SmsExceptionInterface`.

```php
<?php
namespace Psr\Sms;

/** The ONE mandatory contract; every gateway MUST implement send(). */
interface SmsClientInterface
{
    /**
     * The recipient MUST be normalized to the provider wire shape and the segment
     * count SHOULD be forwarded. The result MUST carry the provider id when given
     * and MAY carry inline status/price/balance (Mitake/Every8d).
     * @throws Exception\InvalidArgumentExceptionInterface
     * @throws Exception\NetworkExceptionInterface
     * @throws Exception\AuthenticationExceptionInterface
     * @throws Exception\SmsExceptionInterface
     */
    public function send(MessageInterface $message): SmsResultInterface;
}
```

### 3.2 `CapabilityAwareInterface` — grade: **core**

> **Purpose.** Capability discovery for graceful degradation. No provider supports the full union of features, so callers negotiate. Lineage: Symfony Notifier's `supports()`.

**Rules.**

1. A driver **MUST** implement `supports()`.
2. A caller **MUST NOT** invoke an extension operation unless `supports()` returns `true` for the corresponding `Capability` case **OR** an `instanceof` check against the extension interface confirms support.
3. If a caller invokes an unsupported extension anyway, the driver **SHOULD** throw `UnsupportedCapabilityException`.
4. `supports()` **MUST** be free of side effects.

```php
<?php
namespace Psr\Sms;

enum Capability: string
{
    case BULK = 'bulk';
    case STATUS_QUERY = 'status_query';
    case DELIVERY_RECEIPT = 'delivery_receipt';
    case INBOUND = 'inbound';
    case SCHEDULE = 'schedule';
    case CANCEL = 'cancel';
    case BALANCE = 'balance';
    case VERIFICATION = 'verification';
    case FLASH = 'flash';
    case BINARY = 'binary';
    case MMS = 'mms';
    case TEMPLATE = 'template';
    // Added by refinement: the discovery vocabulary the gaps repeatedly need.
    case IDEMPOTENCY = 'idempotency';
    case MAX_PRICE = 'max_price';
    case DRY_RUN = 'dry_run';
    case MESSAGING_SERVICE = 'messaging_service';
    case MULTICHANNEL = 'multichannel';
    case MULTI_CHANNEL_FALLBACK = 'multi_channel_fallback';
    case RCS = 'rcs';
    case REDACT = 'redact';
    case TOPIC_FANOUT = 'topic_fanout';
    case ASYNC_BULK = 'async_bulk';
    case HLR_LOOKUP = 'hlr_lookup';
    case COST_REPORT = 'cost_report';
    case MESSAGE_FEEDBACK = 'message_feedback';
    case ACCOUNT_PREFS = 'account_prefs';
    case TEMPLATE_REGISTRY = 'template_registry';
}

interface CapabilityAwareInterface
{
    /**
     * @return bool Callers MUST NOT invoke an extension unless true or
     *         instanceof confirms it.
     */
    public function supports(Capability $capability): bool;
}
```

---

## 4. Extension Interfaces

Each extension is segregated and capability-gated. A driver implements only the ones its provider supports.

### 4.1 `BulkSmsClientInterface` — grade: **extension** — `Capability::BULK`

> **Covers:** bulk/batch send (#2), per-recipient personalisation (#3).
> **Why segregated:** common but not universal. A *list of Messages* expresses both plain bulk (identical bodies) and personalisation (distinct params) without a second method.

**Rules.**

1. The adapter **MUST** translate the list into the provider's native batch encoding (array / delimiter-packed / comma-joined / ClientID-prefixed lines / parallel arrays / per-recipient loop).
2. The adapter **MUST** chunk transparently to the provider cap (Mitake: 500 records per `SmBulkSend` batch).
3. The returned `SmsResultInterface[]` **MUST** be in input order and one-to-one with the input messages: the result at index *i* corresponds to `$messages[i]`, and `count($results) === count($messages)`.
4. **Partial-failure model.** A per-recipient failure **MUST** be represented as a result whose `isSuccessful()` returns `false` (carrying its `getError()`), **NOT** by throwing. The method **MUST** throw only on a whole-batch / transport failure that prevented *any* per-recipient outcome (e.g. rejected credentials, a connection that never completed, a malformed batch the provider refused wholesale). When some recipients succeed and others fail, `sendBulk()` **MUST** return and let the caller inspect each result.

```php
<?php
namespace Psr\Sms;

interface BulkSmsClientInterface extends SmsClientInterface
{
    /**
     * Each Message MAY carry distinct params (personalization); identical bodies
     * = plain bulk. MUST translate to native batch encoding and MUST chunk to the
     * provider cap (Mitake 500). Order MUST match input.
     * @param MessageInterface[] $messages
     * @return SmsResultInterface[]
     * @throws Exception\InvalidArgumentExceptionInterface
     * @throws Exception\SmsExceptionInterface
     */
    public function sendBulk(array $messages): array;
}
```

### 4.2 `DeliveryStatusQueryInterface` — grade: **extension** — `Capability::STATUS_QUERY`

> **Covers:** DLR poll (#22), status normalisation (#24), cost/segment reporting (#36).
> **Why segregated:** polling is common but not universal (SMPP is push-only; AWS EUM v2 is event-only and has `supports(Capability::STATUS_QUERY) === false`).

**Rules.**

1. `getStatus()` and `getStatuses()` **MUST** return canonical `DeliveryState` plus the raw provider code.
2. `getStatuses()` **MUST** chunk transparently to the provider cap (Mitake: 100 msgids per `SmQuery`).
3. Querying an unknown or already-consumed id **MUST** throw `UnknownMessageExceptionInterface`.

```php
<?php
namespace Psr\Sms;

interface DeliveryStatusQueryInterface
{
    /**
     * The returned status MUST carry the canonical state + raw code.
     * @throws Exception\UnknownMessageExceptionInterface
     * @throws Exception\SmsExceptionInterface
     */
    public function getStatus(string $messageId): DeliveryStatusInterface;

    /**
     * Batch (Mitake up to 100). MUST chunk transparently.
     * @param string[] $messageIds
     * @return DeliveryStatusInterface[] Keyed by id.
     * @throws Exception\SmsExceptionInterface
     */
    public function getStatuses(array $messageIds): array;
}
```

### 4.3 `DeliveryReceiptParserInterface` — grade: **extension** — `Capability::DELIVERY_RECEIPT`

> **Covers:** DLR webhook (#21), status normalisation (#24), webhook signature verification (#40), cost/segment reporting (#36), feedback conversion (#25).
> **Why segregated:** parsing a callback is a distinct contract from both `send()` and from MO ingestion. Some providers need a specific ack body (Mitake's `magicid`).

**Rules.**

1. `parseReceipt()` **MUST** verify callback authenticity where the provider supports it, throwing `WebhookVerificationExceptionInterface` on failure. The parser **MUST NOT** trust an unverified payload.
2. `getAcknowledgement()` **MUST** return the exact body the caller must echo to ack, or `null` for a plain HTTP 200. For Mitake it **MUST** be `"magicid=sms_gateway_rpack\nmsgid=NNN\n"`.
3. The doc shape `($serverParams, $rawBody)` is relaxed by `EventDestinationParserInterface` (§4.10) so push-only providers fit.

```php
<?php
namespace Psr\Sms;

interface DeliveryReceiptParserInterface
{
    /**
     * @param array $serverParams Query/headers/body (Mitake GET callback via
     *        $_GET/$_SERVER). MUST verify authenticity.
     * @param string $rawBody Raw body for signature checks; '' if query-only.
     * @throws Exception\WebhookVerificationExceptionInterface
     * @throws Exception\InvalidArgumentExceptionInterface
     */
    public function parseReceipt(array $serverParams, string $rawBody): DeliveryStatusInterface;

    /**
     * Body the caller MUST return to ack, or null for plain 200.
     * Mitake MUST be "magicid=sms_gateway_rpack\nmsgid=NNN\n".
     */
    public function getAcknowledgement(DeliveryStatusInterface $status): ?string;
}
```

### 4.4 `InboundMessageReceiverInterface` — grade: **extension** — `Capability::INBOUND`

> **Covers:** inbound MO (#26), two-way conversation (#27), MO reassembly (#28), premium subscription (#55-style).
> **Why segregated:** two-way is optional and absent on many one-way TW/CN providers (including Mitake).

**Rules.**

1. `parseInbound()` **MUST** verify authenticity where supported.
2. Multipart MO **SHOULD** be reassembled by UDH ref+seq; for pre-split providers (Vonage emits separate webhook calls per part), the caller uses the concat metadata on `InboundMessageInterface` to buffer and reassemble across invocations.
3. `pollInbound()` for webhook-only providers **MAY** return `[]`, and **MAY** be consume-once.

```php
<?php
namespace Psr\Sms;

interface InboundMessageReceiverInterface
{
    /**
     * Multipart SHOULD be reassembled by UDH ref+seq; MUST verify authenticity
     * where supported.
     * @throws Exception\WebhookVerificationExceptionInterface
     * @throws Exception\InvalidArgumentExceptionInterface
     */
    public function parseInbound(array $serverParams, string $rawBody): InboundMessageInterface;

    /**
     * Poll buffered MO (Kavenegar/Tencent/Infobip). Webhook-only MAY return [];
     * MAY be consume-once.
     * @return InboundMessageInterface[]
     * @throws Exception\SmsExceptionInterface
     */
    public function pollInbound(int $limit = 100): array;
}
```

### 4.5 `SchedulableSmsClientInterface` — grade: **extension** — `Capability::SCHEDULE` (+ `Capability::CANCEL`)

> **Covers:** scheduled send (#13), cancel/reschedule (#14), validity/TTL (#15), delivery window (#16).
> **Why segregated & gated:** AWS SNS and CN clouds have no native scheduling; cancel is niche and meaningless without scheduling, so they share one interface.

**Rules.**

1. `schedule()` **MUST** translate the `Schedule` natively (Mitake `dlvtime` `YYYYMMDDHHMMSS` or a seconds offset). The message's `getSchedule()` **MUST** be non-null.
2. The returned result **MUST** carry an id usable with `cancel()`.
3. For Twilio, `schedule()` **MUST** receive a messaging-service `Sender` (see §6.4); otherwise the driver **MUST** throw `InvalidArgumentExceptionInterface`.
4. `cancel()` is best-effort (Mitake `SmCancel`, up to 100) and **MAY** fail if already dispatched.

```php
<?php
namespace Psr\Sms;

interface SchedulableSmsClientInterface extends SmsClientInterface
{
    /**
     * MUST translate Schedule natively (Mitake dlvtime YYYYMMDDHHMMSS or seconds
     * offset). Result MUST carry an id usable with cancel(). For Twilio the
     * Sender MUST be a messaging service; otherwise throw InvalidArgument.
     * @param MessageInterface $message getSchedule() MUST be non-null.
     * @throws Exception\InvalidArgumentExceptionInterface
     * @throws Exception\SmsExceptionInterface
     */
    public function schedule(MessageInterface $message): SmsResultInterface;

    /**
     * Best-effort cancel (Mitake SmCancel up to 100). MAY fail if dispatched.
     * @throws Exception\SmsExceptionInterface
     */
    public function cancel(string $messageId): bool;
}
```

### 4.6 `BalanceInterface` — grade: **extension** — `Capability::BALANCE`

> **Covers:** balance/credit query (#35).
> **Why gated:** common on TW/regional providers, absent on CN clouds and AWS (billing console only). Per-message *cost* rides on `SmsResult`/`DeliveryStatus`, not here.

```php
<?php
namespace Psr\Sms;

interface BalanceInterface
{
    /**
     * @throws Exception\SmsExceptionInterface
     */
    public function getBalance(): Balance;
}
```

### 4.7 `VerificationInterface` — grade: **extension** — `Capability::VERIFICATION`

> **Covers:** managed OTP (#29), multi-channel fallback (#30), silent network auth (#31), transaction binding (#32), in-app token (#33). Raw self-managed OTP (#34) needs *only* `SmsClientInterface::send()` and is not modelled here.
> **Why hard-segregated:** managed verify (opaque code, provider stores & checks) is a fundamentally different contract from raw OTP. `start()` returns a rich result; `check()` distinguishes pending/approved/canceled/expired/max_attempts because login UIs branch on it. The recipient is widened beyond `PhoneNumber` (Twilio email channel). First-class `OPT_*` constants replace magic array keys.

```php
<?php
namespace Psr\Sms;

/**
 * Verification channel names. The SMS, VOICE and SNA channels are all
 * addressable by E.164 (RecipientKind::PHONE); the remaining channels map to
 * their own RecipientKind. SNA is silent network auth (the single SNA case;
 * do not add a duplicate).
 */
enum VerificationChannelType: string
{
    case SMS = 'sms';
    case VOICE = 'voice';
    case EMAIL = 'email';
    case WHATSAPP = 'whatsapp';
    case RCS = 'rcs';
    case SNA = 'sna';      // silent network auth (the single SNA case; do not add a duplicate)
    case AUTO = 'auto';
}

/** Recipient kind, so every channel has an expressible recipient. */
enum RecipientKind: string
{
    case PHONE = 'phone';        // addressable by E.164 (SMS, VOICE and SNA channels)
    case EMAIL = 'email';        // EMAIL channel
    case WHATSAPP = 'whatsapp';  // WHATSAPP channel
    case RCS = 'rcs';            // RCS channel
}

interface VerificationRecipientInterface
{
    /** @return string the addressable value (E.164, email, etc.) */
    public function getValue(): string;

    /** Recipient kind, so every channel has an expressible recipient. */
    public function getKind(): RecipientKind;
}

interface VerificationResultInterface
{
    /** @return string canonical: pending|approved|canceled|expired|max_attempts */
    public function getStatus(): string;
    public function isApproved(): bool;
    /** @return string provider-native status */
    public function getRawStatus(): string;
    /** @return string|int|null provider error code (fraud/locked vs wrong code) */
    public function getErrorCode(): string|int|null;
    /** @return int|null attempts remaining */
    public function getRemainingAttempts(): ?int;
}

interface VerificationStartInterface
{
    /** @return string verification id/sid */
    public function getId(): string;
    /** Resolved channel actually used (auto fallback). */
    public function getChannel(): VerificationChannelType;
    /** @return string canonical status */
    public function getStatus(): string;
    /** @return string|null SNA / silent-auth redirect/check URL */
    public function getSnaUrl(): ?string;
    /** @return array raw provider payload */
    public function getRaw(): array;
}

interface VerificationInterface
{
    // First-class option keys (instead of magic array keys)
    public const OPT_CODE_LENGTH = 'code_length';
    public const OPT_CUSTOM_CODE = 'custom_code';
    public const OPT_EXPIRY = 'expiry';
    public const OPT_CHANNEL_TIMEOUT = 'channel_timeout';
    public const OPT_BRAND = 'brand';
    public const OPT_TEMPLATE_ID = 'template_id';
    public const OPT_TEMPLATE_SUBSTITUTIONS = 'template_substitutions';
    public const OPT_FRIENDLY_NAME = 'friendly_name';
    public const OPT_APP_HASH = 'app_hash';
    public const OPT_FRAUD_CHECK = 'fraud_check';
    public const OPT_RISK_CHECK = 'risk_check';
    public const OPT_RATE_LIMITS = 'rate_limits';
    public const OPT_TAGS = 'tags';
    public const OPT_CLIENT_REF = 'client_ref';
    public const OPT_LOCALE = 'locale';
    public const OPT_DEVICE_IP = 'device_ip';
    public const OPT_SNA_CLIENT_TOKEN = 'sna_client_token';
    public const OPT_WORKFLOW = 'workflow';
    public const OPT_TXN_AMOUNT = 'txn_amount';
    public const OPT_TXN_PAYEE = 'txn_payee';

    /**
     * Start a verification. $to may be a phone or email recipient. For
     * multi-channel workflows pass an ordered VerificationChannel[] under
     * OPT_WORKFLOW (each carries its own channel + recipient).
     * @param array $options keyed by OPT_* constants
     * @throws Exception\VerificationExceptionInterface
     * @throws Exception\SmsExceptionInterface
     */
    public function start(VerificationRecipientInterface $to, array $options = []): VerificationStartInterface;

    /**
     * @throws Exception\VerificationExceptionInterface
     */
    public function check(string $verificationId, string $code): VerificationResultInterface;

    /**
     * Check by recipient when the id was not persisted (Twilio Code + To).
     * @throws Exception\VerificationExceptionInterface
     */
    public function checkByRecipient(VerificationRecipientInterface $to, string $code): VerificationResultInterface;

    /**
     * @throws Exception\SmsExceptionInterface
     */
    public function cancel(string $verificationId): bool;

    /**
     * @throws Exception\SmsExceptionInterface
     */
    public function resend(string $verificationId): VerificationStartInterface;
}
```

#### 4.7.1 `VerificationChannel` value object

> An ordered workflow step (Vonage Verify v2 allows max 3). Each step carries its own channel + recipient so a step can target a distinct address that a flat channel-name list cannot.

```php
<?php
namespace Psr\Sms;

final class VerificationChannel
{
    /**
     * @param VerificationChannelType $channel The channel for this workflow step.
     * @param array $extra Per-channel fields (redirectUrl, from, appHash, templateId).
     */
    public function __construct(
        public readonly VerificationChannelType $channel,
        public readonly VerificationRecipientInterface $recipient,
        public readonly array $extra = []
    ) {
    }

    public function getChannel(): VerificationChannelType { return $this->channel; }
    public function getRecipient(): VerificationRecipientInterface { return $this->recipient; }
    /** @return mixed */
    public function get(string $name, mixed $default = null): mixed { return array_key_exists($name, $this->extra) ? $this->extra[$name] : $default; }
    /** @return array */
    public function all(): array { return $this->extra; }
}
```

### 4.8 Small capability-gated extension interfaces

> **Covers:** redaction (#53), async bulk (#55), topic fan-out (#57), account prefs (#58), feedback (#25), HLR lookup (#56), cost report (#37), template registry (#44/#45). Each keeps the core untouched and degrades via `supports()`.

```php
<?php
namespace Psr\Sms;

/** Capability::REDACT — Twilio empty-body redaction. */
interface RedactableInterface
{
    /**
     * @throws Exception\SmsExceptionInterface
     */
    public function redact(string $messageId): bool;
}

/** Capability::ASYNC_BULK — Twilio Bulk operationId + poll-later. */
interface AsyncBulkInterface
{
    /**
     * @param MessageInterface[] $messages
     * @throws Exception\SmsExceptionInterface
     */
    public function sendBatch(array $messages): BatchResultInterface;
    /**
     * @throws Exception\SmsExceptionInterface
     */
    public function getBatchStatus(string $batchId): BatchResultInterface;
}

interface BatchResultInterface
{
    /** @return string provider operation/batch id */
    public function getBatchId(): string;
    /** @return string canonical batch state */
    public function getStatus(): string;
}

/** Capability::TOPIC_FANOUT — AWS SNS topic fan-out. */
interface TopicPublishInterface
{
    /**
     * @param string $topicTarget topic/ARN
     * @throws Exception\SmsExceptionInterface
     */
    public function publishToTopic(string $topicTarget, MessageInterface $m): SmsResultInterface;
}

/** Capability::HLR_LOOKUP — MessageBird HLR. */
interface NumberLookupInterface
{
    /**
     * @throws Exception\SmsExceptionInterface
     */
    public function lookup(PhoneNumber $number): LookupResult;
}

/** Capability::COST_REPORT — async per-message cost. */
interface CostReportInterface
{
    /**
     * @throws Exception\SmsExceptionInterface
     */
    public function getCost(string $messageId): ?Money;
    /**
     * @param string[] $messageIds
     * @return array Money keyed by id.
     * @throws Exception\SmsExceptionInterface
     */
    public function getCosts(array $messageIds): array;
}

/** Capability::MESSAGE_FEEDBACK — AWS PutMessageFeedback / Sinch delivery_feedback. */
interface MessageFeedbackInterface
{
    /**
     * @param string $status RECEIVED|FAILED
     * @throws Exception\SmsExceptionInterface
     */
    public function putFeedback(string $messageId, string $status): bool;
}

/** Capability::ACCOUNT_PREFS — AWS SNS account-level SMS attributes. */
interface AccountPreferencesInterface
{
    /** @return array */
    public function getSmsAttributes(): array;
    public function setSmsAttributes(array $attributes): void;
}

/** Capability::TEMPLATE_REGISTRY — India DLT / CN template submit + status. */
interface TemplateRegistryInterface
{
    /**
     * @return string template id
     * @throws Exception\SmsExceptionInterface
     */
    public function submitTemplate(TemplateDefinition $tpl): string;
    /**
     * @return string canonical approval status
     * @throws Exception\SmsExceptionInterface
     */
    public function getTemplateStatus(string $templateId): string;
}
```

### 4.9 `SignatureVerifierInterface` — grade: **extension** (injected into parsers)

> **Covers:** webhook authenticity verification (#40). Named, injectable, testable. Parsers (`DeliveryReceiptParserInterface`, `InboundMessageReceiverInterface`) are constructed with a signing secret, algorithm, and allowed clock skew and **SHOULD** depend on a `SignatureVerifierInterface`.

```php
<?php
namespace Psr\Sms;

interface SignatureVerifierInterface
{
    /**
     * @param array $params flattened request params (sorted internally)
     * @param string $signature provided sig
     * @param int|null $timestamp request timestamp for skew check
     * @throws Exception\WebhookVerificationExceptionInterface on mismatch or stale timestamp
     */
    public function verify(array $params, string $signature, ?int $timestamp = null): bool;
}
```

### 4.10 `EventDestinationParserInterface` — grade: **extension**

> **Covers:** push-only event ingestion (#59). AWS EUM v2 has no poll-by-id API; events arrive as SNS/Kinesis/CloudWatch records. Such providers return `supports(Capability::STATUS_QUERY) === false`.

```php
<?php
namespace Psr\Sms;

interface EventDestinationParserInterface
{
    /**
     * Parse an opaque push event (SNS notification, Kinesis record, CloudWatch
     * log entry) into a delivery status or inbound message.
     * @param string $rawEvent the raw event payload
     * @throws Exception\WebhookVerificationExceptionInterface on auth failure
     */
    public function parseEvent(string $rawEvent): DeliveryStatusInterface|InboundMessageInterface;
}
```

---

## 5. Value Objects — Messages

### 5.1 `MessageInterface`

> Immutable (PSR-7 spirit). Carries recipient, sender, **bifurcated payload (body XOR template)**, encoding, type, validity, schedule, idempotency key, cross-cutting optional getters, rich-content value objects, compliance fields, and an untyped provider-options escape hatch. Every `with*()` **MUST** return a clone; validation **MUST** occur in `__construct()`/`with*()`.
>
> The `getProviderOptions()` escape hatch is the single biggest lever: it absorbs niche per-provider send-time flags (Twilio `RiskCheck`/`SmartEncoded`/`ShortenUrls`, Vonage `trusted_recipient`/`account_ref`, AWS `Context`/`ConfigurationSetName`/`MessageFeedbackEnabled`) without per-flag core changes. Keys **SHOULD** be provider-prefixed (e.g. `'twilio.RiskCheck'`); adapters ignore keys they do not recognise.

```php
<?php
namespace Psr\Sms;

/** Immutable. Every with*() MUST return a clone; validation MUST occur in __construct/with*(). */
interface MessageInterface
{
    // --- Core addressing & payload ---------------------------------------
    public function getTo(): PhoneNumber;
    public function getFrom(): ?Sender;
    /** @return string|null Null when templated. */
    public function getBody(): ?string;
    public function isTemplated(): bool;
    public function getTemplateId(): ?string;
    public function getSignName(): ?string;
    /** @return array name=>value */
    public function getTemplateParams(): array;
    public function getEncoding(): Encoding;
    public function getType(): MessageType;
    public function getValidity(): ?ValidityPeriod;
    public function getSchedule(): ?Schedule;

    // --- Cross-cutting optional getters (refinement) ----------------------
    /** @return string|null Native idempotency/dedup key (Twilio Idempotency-Key; Mitake clientid). */
    public function getIdempotencyKey(): ?string;
    /** @return string|null Per-message correlation tag echoed on the DLR (Vonage client_ref, <=100 chars). */
    public function getClientRef(): ?string;
    /** @return string|null Per-message delivery-status callback URL (Twilio StatusCallback; Mitake response). */
    public function getStatusCallbackUrl(): ?string;
    /** @return bool|null Whether a delivery receipt is requested; null = provider default. */
    public function getDeliveryReceiptRequested(): ?bool;
    /** @return int|null GSM message class 0-3 (0 = flash); null = default. */
    public function getMessageClass(): ?int;
    /** @return bool Validate/estimate without dispatching; honored only when supports(Capability::DRY_RUN). */
    public function isDryRun(): bool;
    /** @return Money|null Per-message price ceiling; send fails if cost would exceed it. */
    public function getMaxPrice(): ?Money;

    // --- Rich / binary / template / multi-channel content -----------------
    /** @return string[] MMS/rich media URLs (gated Capability::MMS). */
    public function getMediaUrls(): array;
    /** @return BinaryContent|null UDH + protocol-id (gated Capability::BINARY). */
    public function getBinary(): ?BinaryContent;
    /** @return TemplateReference|null id + variables (gated Capability::TEMPLATE, exclusive with body). */
    public function getTemplate(): ?TemplateReference;
    /** @return ChannelFallback|null Ordered multi-channel fallback (gated Capability::MULTI_CHANNEL_FALLBACK). */
    public function getChannels(): ?ChannelFallback;

    // --- Compliance & escape hatch ---------------------------------------
    public function getComplianceFields(): ?ComplianceFieldsInterface;
    /** @return array Provider-namespaced escape hatch merged into the wire payload. */
    public function getProviderOptions(): array;

    // --- Mutators (new-instance-on-write) --------------------------------
    /** Returns a new instance using $body (clears any template). */
    public function withBody(string $body): MessageInterface;
    public function withTemplate(string $templateId, array $params = [], ?string $signName = null): MessageInterface;
    public function withFrom(Sender $from): MessageInterface;
    public function withEncoding(Encoding $encoding): MessageInterface;
    public function withType(MessageType $type): MessageInterface;
    public function withValidity(ValidityPeriod $validity): MessageInterface;
    public function withSchedule(Schedule $schedule): MessageInterface;
    public function withIdempotencyKey(string $key): MessageInterface;
    public function withClientRef(string $ref): MessageInterface;
    public function withStatusCallbackUrl(string $url): MessageInterface;
    public function withMaxPrice(Money $max): MessageInterface;
    public function withComplianceFields(ComplianceFieldsInterface $fields): MessageInterface;
    public function withProviderOptions(array $options): MessageInterface;
}
```

### 5.2 `Message` — default immutable implementation

> Validates the body/template XOR in the `create()` factory and on every mutator. Properties are `readonly`; each `with*()` rebuilds a fresh instance (readonly props cannot be cloned-and-reassigned before 8.3). Defaults: `Encoding::AUTO`, `MessageType::TRANSACTIONAL`.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

class Message implements MessageInterface
{
    /**
     * The all-args constructor is private so the only public entry points are the
     * two-arg constructor below (via the static factory) and the immutable with*()
     * methods, which rebuild a fresh instance because readonly properties cannot be
     * cloned-and-reassigned before PHP 8.3.
     */
    private function __construct(
        private readonly PhoneNumber $to,
        private readonly ?Sender $from = null,
        private readonly ?string $body = null,
        private readonly ?string $templateId = null,
        private readonly ?string $signName = null,
        private readonly array $templateParams = [],
        private readonly Encoding $encoding = Encoding::AUTO,
        private readonly MessageType $type = MessageType::TRANSACTIONAL,
        private readonly ?ValidityPeriod $validity = null,
        private readonly ?Schedule $schedule = null,
        private readonly ?string $idempotencyKey = null,
        private readonly ?string $clientRef = null,
        private readonly ?string $statusCallbackUrl = null,
        private readonly ?bool $deliveryReceiptRequested = null,
        private readonly ?int $messageClass = null,
        private readonly bool $dryRun = false,
        private readonly ?Money $maxPrice = null,
        private readonly array $mediaUrls = [],
        private readonly ?BinaryContent $binary = null,
        private readonly ?TemplateReference $template = null,
        private readonly ?ChannelFallback $channels = null,
        private readonly ?ComplianceFieldsInterface $compliance = null,
        private readonly array $providerOptions = []
    ) {
    }

    /**
     * @param string|null $body Non-empty string, or null for template mode.
     * @throws InvalidArgumentException
     */
    public static function create(PhoneNumber $to, ?string $body = null): self
    {
        if ($body === '') {
            throw new InvalidArgumentException('Body must not be empty; use a template or non-empty body.');
        }
        return new self($to, null, $body);
    }

    /**
     * Internal helper to rebuild with named overrides. Each with*() delegates here.
     */
    private function copyWith(array $overrides): self
    {
        return new self(
            $overrides['to'] ?? $this->to,
            array_key_exists('from', $overrides) ? $overrides['from'] : $this->from,
            array_key_exists('body', $overrides) ? $overrides['body'] : $this->body,
            array_key_exists('templateId', $overrides) ? $overrides['templateId'] : $this->templateId,
            array_key_exists('signName', $overrides) ? $overrides['signName'] : $this->signName,
            array_key_exists('templateParams', $overrides) ? $overrides['templateParams'] : $this->templateParams,
            $overrides['encoding'] ?? $this->encoding,
            $overrides['type'] ?? $this->type,
            array_key_exists('validity', $overrides) ? $overrides['validity'] : $this->validity,
            array_key_exists('schedule', $overrides) ? $overrides['schedule'] : $this->schedule,
            array_key_exists('idempotencyKey', $overrides) ? $overrides['idempotencyKey'] : $this->idempotencyKey,
            array_key_exists('clientRef', $overrides) ? $overrides['clientRef'] : $this->clientRef,
            array_key_exists('statusCallbackUrl', $overrides) ? $overrides['statusCallbackUrl'] : $this->statusCallbackUrl,
            array_key_exists('deliveryReceiptRequested', $overrides) ? $overrides['deliveryReceiptRequested'] : $this->deliveryReceiptRequested,
            array_key_exists('messageClass', $overrides) ? $overrides['messageClass'] : $this->messageClass,
            $overrides['dryRun'] ?? $this->dryRun,
            array_key_exists('maxPrice', $overrides) ? $overrides['maxPrice'] : $this->maxPrice,
            $overrides['mediaUrls'] ?? $this->mediaUrls,
            array_key_exists('binary', $overrides) ? $overrides['binary'] : $this->binary,
            array_key_exists('template', $overrides) ? $overrides['template'] : $this->template,
            array_key_exists('channels', $overrides) ? $overrides['channels'] : $this->channels,
            array_key_exists('compliance', $overrides) ? $overrides['compliance'] : $this->compliance,
            $overrides['providerOptions'] ?? $this->providerOptions
        );
    }

    public function getTo(): PhoneNumber { return $this->to; }
    public function getFrom(): ?Sender { return $this->from; }
    public function getBody(): ?string { return $this->body; }
    public function isTemplated(): bool { return $this->templateId !== null || $this->template !== null; }
    public function getTemplateId(): ?string { return $this->templateId; }
    public function getSignName(): ?string { return $this->signName; }
    public function getTemplateParams(): array { return $this->templateParams; }
    public function getEncoding(): Encoding { return $this->encoding; }
    public function getType(): MessageType { return $this->type; }
    public function getValidity(): ?ValidityPeriod { return $this->validity; }
    public function getSchedule(): ?Schedule { return $this->schedule; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function getClientRef(): ?string { return $this->clientRef; }
    public function getStatusCallbackUrl(): ?string { return $this->statusCallbackUrl; }
    public function getDeliveryReceiptRequested(): ?bool { return $this->deliveryReceiptRequested; }
    public function getMessageClass(): ?int { return $this->messageClass; }
    public function isDryRun(): bool { return $this->dryRun; }
    public function getMaxPrice(): ?Money { return $this->maxPrice; }
    public function getMediaUrls(): array { return $this->mediaUrls; }
    public function getBinary(): ?BinaryContent { return $this->binary; }
    public function getTemplate(): ?TemplateReference { return $this->template; }
    public function getChannels(): ?ChannelFallback { return $this->channels; }
    public function getComplianceFields(): ?ComplianceFieldsInterface { return $this->compliance; }
    public function getProviderOptions(): array { return $this->providerOptions; }

    public function withBody(string $body): MessageInterface
    {
        if ($body === '') {
            throw new InvalidArgumentException('Body must be non-empty string.');
        }
        return $this->copyWith([
            'body' => $body,
            'templateId' => null,
            'signName' => null,
            'templateParams' => [],
            'template' => null,
        ]);
    }

    public function withTemplate(string $templateId, array $params = [], ?string $signName = null): MessageInterface
    {
        if ($templateId === '') {
            throw new InvalidArgumentException('Template id must be non-empty string.');
        }
        return $this->copyWith([
            'templateId' => $templateId,
            'templateParams' => $params,
            'signName' => $signName,
            'body' => null,
        ]);
    }

    public function withFrom(Sender $from): MessageInterface { return $this->copyWith(['from' => $from]); }

    public function withEncoding(Encoding $encoding): MessageInterface
    {
        return $this->copyWith(['encoding' => $encoding]);
    }

    public function withType(MessageType $type): MessageInterface
    {
        return $this->copyWith(['type' => $type]);
    }

    public function withValidity(ValidityPeriod $validity): MessageInterface { return $this->copyWith(['validity' => $validity]); }
    public function withSchedule(Schedule $schedule): MessageInterface { return $this->copyWith(['schedule' => $schedule]); }

    public function withIdempotencyKey(string $key): MessageInterface
    {
        if ($key === '') {
            throw new InvalidArgumentException('Idempotency key must be non-empty string.');
        }
        return $this->copyWith(['idempotencyKey' => $key]);
    }

    public function withClientRef(string $ref): MessageInterface
    {
        if ($ref === '' || strlen($ref) > 100) {
            throw new InvalidArgumentException('Client ref must be a non-empty string of at most 100 chars.');
        }
        return $this->copyWith(['clientRef' => $ref]);
    }

    public function withStatusCallbackUrl(string $url): MessageInterface
    {
        if ($url === '') {
            throw new InvalidArgumentException('Status callback URL must be a non-empty string.');
        }
        return $this->copyWith(['statusCallbackUrl' => $url]);
    }

    public function withMaxPrice(Money $max): MessageInterface { return $this->copyWith(['maxPrice' => $max]); }
    public function withComplianceFields(ComplianceFieldsInterface $fields): MessageInterface { return $this->copyWith(['compliance' => $fields]); }
    public function withProviderOptions(array $options): MessageInterface { return $this->copyWith(['providerOptions' => $options]); }
}
```

### 5.3 `PhoneNumber`

> Immutable, validated. E.164 (default), national (TW `09x`), or short code. Strips display notation (space/dash/dot/parens) in `__construct()`.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

class PhoneNumber
{
    public const FORMAT_E164 = 'e164';
    public const FORMAT_NATIONAL = 'national';
    public const FORMAT_SHORTCODE = 'shortcode';

    public readonly string $value;
    public readonly string $format;

    /**
     * @param string $value Display notation (space/dash/dot/parens) is stripped.
     * @param string $format One of FORMAT_*.
     * @throws InvalidArgumentException
     */
    public function __construct(string $value, string $format = self::FORMAT_E164)
    {
        $clean = preg_replace('/[\s().\-]/', '', $value);
        switch ($format) {
            case self::FORMAT_E164:
                if (!preg_match('/^\+[1-9]\d{1,14}$/', $clean)) {
                    throw new InvalidArgumentException('Invalid E.164 number: ' . $value);
                }
                break;
            case self::FORMAT_NATIONAL:
                if (!preg_match('/^\d{4,15}$/', $clean)) {
                    throw new InvalidArgumentException('Invalid national number: ' . $value);
                }
                break;
            case self::FORMAT_SHORTCODE:
                if (!preg_match('/^\d{3,8}$/', $clean)) {
                    throw new InvalidArgumentException('Invalid short code: ' . $value);
                }
                break;
            default:
                throw new InvalidArgumentException('Unknown phone number format: ' . $format);
        }
        $this->value = $clean;
        $this->format = $format;
    }

    public function getValue(): string { return $this->value; }
    public function getFormat(): string { return $this->format; }
    public function __toString(): string { return $this->value; }
}
```

### 5.4 `Sender` (discriminated origination)

> Immutable. Named static factories give a typed discriminator (`getKind()`) so origination is not an opaque string. Twilio scheduling requires `fromMessagingService()`; AWS origination kinds map to `fromPool()`/`fromArn()`/`fromRcsAgent()`.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

final class Sender
{
    public const KIND_NUMBER = 'number';
    public const KIND_ALPHANUMERIC = 'alphanumeric';
    public const KIND_MESSAGING_SERVICE = 'messaging_service';
    public const KIND_POOL = 'pool';
    public const KIND_ARN = 'arn';
    public const KIND_RCS_AGENT = 'rcs_agent';

    /**
     * @param string $kind One of KIND_*.
     * @throws InvalidArgumentException
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $value
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Sender value must be a non-empty string.');
        }
        if ($kind === self::KIND_ALPHANUMERIC && strlen($value) > 11) {
            throw new InvalidArgumentException('Alphanumeric sender id must be at most 11 chars.');
        }
    }

    public static function fromNumber(string $e164): self { return new self(self::KIND_NUMBER, $e164); }
    public static function fromAlphanumeric(string $name): self { return new self(self::KIND_ALPHANUMERIC, $name); }
    public static function fromMessagingService(string $sid): self { return new self(self::KIND_MESSAGING_SERVICE, $sid); }
    public static function fromPool(string $poolId): self { return new self(self::KIND_POOL, $poolId); }
    public static function fromArn(string $arn): self { return new self(self::KIND_ARN, $arn); }
    public static function fromRcsAgent(string $agentId): self { return new self(self::KIND_RCS_AGENT, $agentId); }

    /** @return string one of the KIND_* constants */
    public function getKind(): string { return $this->kind; }
    public function getValue(): string { return $this->value; }
    public function isPool(): bool { return $this->kind === self::KIND_POOL || $this->kind === self::KIND_MESSAGING_SERVICE; }
    public function __toString(): string { return $this->value; }
}
```

### 5.5 `ValidityPeriod` (unit-explicit)

> Immutable TTL stored canonically in **milliseconds** to remove the seconds-vs-milliseconds ambiguity. Vonage legacy uses ms (20000–604800000); Messages-API/Twilio use seconds. Each adapter converts from one unambiguous source.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

final class ValidityPeriod
{
    /**
     * @param int $milliseconds > 0
     * @throws InvalidArgumentException
     */
    private function __construct(public readonly int $milliseconds)
    {
        if ($milliseconds <= 0) {
            throw new InvalidArgumentException('Validity must be a positive integer of milliseconds.');
        }
    }

    public static function fromSeconds(int $seconds): self
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Seconds must be a positive integer.');
        }
        return new self($seconds * 1000);
    }
    public static function fromMinutes(int $minutes): self
    {
        if ($minutes <= 0) {
            throw new InvalidArgumentException('Minutes must be a positive integer.');
        }
        return new self($minutes * 60 * 1000);
    }
    public static function fromHours(int $hours): self
    {
        if ($hours <= 0) {
            throw new InvalidArgumentException('Hours must be a positive integer.');
        }
        return new self($hours * 3600 * 1000);
    }
    public static function fromMilliseconds(int $ms): self { return new self($ms); }

    public function toMilliseconds(): int { return $this->milliseconds; }
    /** @return int rounded to whole seconds */
    public function toSeconds(): int { return (int) round($this->milliseconds / 1000); }
    public function toMinutes(): int { return (int) floor($this->milliseconds / 60000); }
}
```

### 5.6 `Schedule`

> Immutable deferred send time accepting any `\DateTimeInterface`. The driver formats it for the wire (Mitake `dlvtime` → `format('YmdHis')`). The stored instance is `readonly`; since callers can pass a mutable `\DateTime`, the constructor still clones defensively (and re-clones on read) so external mutation cannot reach into the value object.

```php
<?php
namespace Psr\Sms;

final class Schedule
{
    private readonly \DateTimeInterface $when;

    /** @param \DateTimeInterface $when Future send time; cloned defensively. */
    public function __construct(\DateTimeInterface $when) { $this->when = clone $when; }

    /** @return \DateTimeInterface Defensive clone. */
    public function getWhen(): \DateTimeInterface { return clone $this->when; }

    /** @param string $format date() format, e.g. 'YmdHis' for Mitake. */
    public function format(string $format): string { return $this->when->format($format); }
}
```

### 5.7 `BinaryContent`

> Immutable. Hex body + optional UDH + TP-PID for OTA / WAP-push / concatenated binary. Gated by `Capability::BINARY`.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

final class BinaryContent
{
    /**
     * @param string $body hex/byte payload
     * @param string|null $udh User Data Header (hex)
     * @param int|null $protocolId TP-PID
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $body,
        public readonly ?string $udh = null,
        public readonly ?int $protocolId = null
    ) {
        if ($body === '') {
            throw new InvalidArgumentException('Binary body required.');
        }
    }
    public function getBody(): string { return $this->body; }
    public function getUdh(): ?string { return $this->udh; }
    public function getProtocolId(): ?int { return $this->protocolId; }
}
```

### 5.8 `TemplateReference`

> Immutable. Template id + variable bindings (Twilio `ContentSid` + `ContentVariables`). Gated by `Capability::TEMPLATE`, mutually exclusive with a free-form body.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

final class TemplateReference
{
    /**
     * @param array $variables key/value bindings
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $id,
        public readonly array $variables = []
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Template id required.');
        }
    }
    public function getId(): string { return $this->id; }
    /** @return array */
    public function getVariables(): array { return $this->variables; }
}
```

### 5.9 `ChannelFallback`

> Immutable ordered list of channel names for multi-channel fallback (try RCS/WhatsApp, degrade to SMS). Gated by `Capability::MULTI_CHANNEL_FALLBACK`.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

final class ChannelFallback
{
    /** @var string[] */
    public readonly array $channels;

    /**
     * @param string[] $channels Ordered channel names (e.g. 'rcs','whatsapp','sms').
     * @throws InvalidArgumentException
     */
    public function __construct(array $channels)
    {
        if (count($channels) === 0) {
            throw new InvalidArgumentException('Channel fallback requires at least one channel.');
        }
        foreach ($channels as $c) {
            if (!is_string($c) || $c === '') {
                throw new InvalidArgumentException('Each channel must be a non-empty string.');
            }
        }
        $this->channels = array_values($channels);
    }
    /** @return string[] Ordered. */
    public function getChannels(): array { return $this->channels; }
}
```

### 5.10 `ComplianceFieldsInterface` + `ComplianceFields`

> Open, multi-identifier container — holds `contentId` AND `entityId` together (Vonage India DLT), an open country-keyed map (AWS `IN_ENTITY_ID`/`IN_TEMPLATE_ID`), `keyword` (AWS short-code program name), and `protectConfigurationId`. The open map means new country/registration keys need no interface change.

```php
<?php
namespace Psr\Sms;

interface ComplianceFieldsInterface
{
    /** @return string|null registered content/template id */
    public function getContentId(): ?string;
    /** @return string|null registered sender/principal entity id (DLT) */
    public function getEntityId(): ?string;
    /** @return string|null AWS Keyword / short-code program name */
    public function getKeyword(): ?string;
    /** @return string|null AWS ProtectConfigurationId */
    public function getProtectConfigurationId(): ?string;
    /**
     * Open country-keyed registration parameters, e.g.
     * ['IN_ENTITY_ID' => '...', 'IN_TEMPLATE_ID' => '...'].
     * @return array
     */
    public function getCountryParameters(): array;
}
```

```php
<?php
namespace Psr\Sms;

final class ComplianceFields implements ComplianceFieldsInterface
{
    private readonly ?string $contentId;
    private readonly ?string $entityId;
    private readonly ?string $keyword;
    private readonly ?string $protectConfigurationId;
    private readonly array $countryParameters;

    /**
     * @param array $fields keys: contentId, entityId, keyword,
     *        protectConfigurationId, countryParameters (array).
     */
    public function __construct(array $fields = [])
    {
        $this->contentId = $fields['contentId'] ?? null;
        $this->entityId = $fields['entityId'] ?? null;
        $this->keyword = $fields['keyword'] ?? null;
        $this->protectConfigurationId = $fields['protectConfigurationId'] ?? null;
        $this->countryParameters = isset($fields['countryParameters']) && is_array($fields['countryParameters'])
            ? $fields['countryParameters'] : [];
    }
    public function getContentId(): ?string { return $this->contentId; }
    public function getEntityId(): ?string { return $this->entityId; }
    public function getKeyword(): ?string { return $this->keyword; }
    public function getProtectConfigurationId(): ?string { return $this->protectConfigurationId; }
    public function getCountryParameters(): array { return $this->countryParameters; }
}
```

---

## 6. Value Objects — Results, Status, Money, Inbound, Balance

### 6.1 `Money`

> Immutable, validated. All price/balance/cost reporting uses it, removing the ambiguity of a bare numeric. `amount` may be string or float (to preserve provider precision); `currency` is a 3-letter ISO-4217 code, upper-cased.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

final class Money
{
    public readonly string $currency;

    /**
     * @param string|float|int $amount
     * @param string $currency 3-letter ISO-4217 code.
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string|float|int $amount,
        string $currency
    ) {
        if (!is_numeric($amount)) {
            throw new InvalidArgumentException('Money amount must be numeric.');
        }
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Money currency must be a 3-letter ISO-4217 code.');
        }
        $this->currency = strtoupper($currency);
    }
    /** @return string|float|int */
    public function getAmount(): string|float|int { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
}
```

### 6.1.1 `PointsCost`

> Immutable, validated. Models a per-message cost expressed in **non-currency points** (Mitake `smsPoint`, Every8d credits), which `Money` cannot carry because `Money` is ISO-4217 3-letter-currency only. Carries the points value and an explicit unit label (default `'POINTS'`). A driver reports per-message cost as `Money` when the provider bills in currency, or as `PointsCost` when it bills in points; both `SmsResultInterface::getPrice()` (Money) and `SmsResultInterface::getPointsCost()` (PointsCost) are nullable and at most one is populated.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

final class PointsCost
{
    /**
     * @param float|int|string $points >= 0
     * @param string $unit Non-currency unit label, e.g. 'POINTS'.
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly float|int|string $points,
        public readonly string $unit = 'POINTS'
    ) {
        if (!is_numeric($points)) {
            throw new InvalidArgumentException('Points cost must be numeric.');
        }
        if ($unit === '') {
            throw new InvalidArgumentException('Points cost unit must be a non-empty string.');
        }
    }
    /** @return float|int|string */
    public function getPoints(): float|int|string { return $this->points; }
    public function getUnit(): string { return $this->unit; }
}
```

### 6.2 `SmsResultInterface`

> Immutable send acknowledgement: provider id, accepted flag, optional inline status, typed price (`Money`) and/or points cost (`PointsCost`), segment count, remaining balance (`Balance`, which models both ISO-4217 currency and non-currency points), duplicate flag (Mitake `clientid` 12h window → `Duplicate=Y`), per-segment sub-results (Vonage legacy splits one send into multiple results), network, and raw payload.

```php
<?php
namespace Psr\Sms;

interface SmsResultInterface
{
    /** @return string|null Provider message id (Mitake msgid). */
    public function getMessageId(): ?string;
    /** @return bool Whether this recipient was accepted. */
    public function isAccepted(): bool;
    /** @return bool Whether this single result succeeded (provider accepted it and no error); false for a failed per-recipient result in a bulk send. */
    public function isSuccessful(): bool;
    /** @return ErrorInfoInterface|null Canonical code + raw code; null on success, set on a failed result. */
    public function getError(): ?ErrorInfoInterface;
    /** @return DeliveryStatusInterface|null Inline status (Mitake/Every8d). */
    public function getInlineStatus(): ?DeliveryStatusInterface;
    /** @return Money|null Per-message price in currency; null if billed in points or reported async. */
    public function getPrice(): ?Money;
    /** @return PointsCost|null Per-message cost in non-currency points (Mitake smsPoint); null if billed in currency. */
    public function getPointsCost(): ?PointsCost;
    /** @return Balance|null Remaining account balance/credit if returned inline (Mitake AccountPoint); models currency or POINTS. */
    public function getRemainingBalance(): ?Balance;
    /** @return int|null GSM-03.38 billed segment count. */
    public function getSegmentCount(): ?int;
    /** @return string|null MCCMNC carrier/network code. */
    public function getNetwork(): ?string;
    /** @return bool Mitake Duplicate=Y. */
    public function isDuplicate(): bool;
    /** @return SmsResultInterface[] Per-segment sub-results; [] if not split. */
    public function getParts(): array;
    /** @return array Raw provider payload. */
    public function getRawResponse(): array;
}
```

### 6.3 `ErrorInfoInterface`

> Small typed carrier for a canonical + raw error pair on a per-recipient result.

```php
<?php
namespace Psr\Sms;

interface ErrorInfoInterface
{
    /** @return string Canonical error category. */
    public function getCanonicalCode(): string;
    /** @return string|int Raw provider code. */
    public function getRawCode(): string|int;
    /** @return string|null Human-readable reason. */
    public function getMessage(): ?string;
}
```

### 6.4 `DeliveryStatusInterface`

> Immutable normalised status: canonical `DeliveryState` PLUS raw provider code, id, recipient, timestamp, reason, finality, plus optional receipt metadata (client ref, segment count, carrier-vs-handset receipt type, per-recipient sub-statuses for MessageBird).

```php
<?php
namespace Psr\Sms;

interface DeliveryStatusInterface
{
    public function getMessageId(): ?string;
    public function getState(): DeliveryState;
    /** @return string|int|null Raw provider code (Mitake numeric or statusstr). */
    public function getProviderCode(): string|int|null;
    /** @return string|int|null Alias of provider-native status/error code. */
    public function getRawCode(): string|int|null;
    public function getRecipient(): ?PhoneNumber;
    /** @return \DateTimeInterface|null Receipt timestamp / scts. */
    public function getTimestamp(): ?\DateTimeInterface;
    /** @return string|null Failure reason. */
    public function getReason(): ?string;
    /** @return string|null Original send correlation tag (clientRef). */
    public function getClientRef(): ?string;
    /** @return int|null Realized billed segment count (Vonage count_total). */
    public function getSegmentCount(): ?int;
    /** @return string|null 'carrier' or 'handset' (Vonage DLR type). */
    public function getReceiptType(): ?string;
    /** @return RecipientStatusInterface[] Per-recipient sub-statuses (MessageBird); [] if single. */
    public function getRecipientStatuses(): array;
    /** @return bool Terminal state. */
    public function isFinal(): bool;
    public function isDelivered(): bool;
}
```

### 6.5 `InboundMessageInterface`

> Immutable normalised MO: from, to, text, inbound MMS media URLs, keyword (STOP/HELP/sub keyword), network, UDH, conversation id (threading), timestamp, raw payload, plus concat-reassembly metadata for providers that pre-split MO into separate webhook calls (Vonage concat-ref/part/total).

```php
<?php
namespace Psr\Sms;

interface InboundMessageInterface
{
    public function getFrom(): PhoneNumber;
    /** @return PhoneNumber|Sender */
    public function getTo(): PhoneNumber|Sender;
    /** @return string Reassembled text. */
    public function getText(): string;
    /** @return string[] Inbound MMS media URLs; [] for a plain text MO. Parallels outbound Message::getMediaUrls(). */
    public function getMediaUrls(): array;
    /** @return string|null First keyword (STOP/HELP/sub keyword). */
    public function getKeyword(): ?string;
    /** @return string|null Carrier id. */
    public function getNetwork(): ?string;
    /** @return string|null Hex UDH for binary/concatenated MO. */
    public function getUdh(): ?string;
    /** @return string|null Stable thread/session id. */
    public function getConversationId(): ?string;
    public function getTimestamp(): ?\DateTimeInterface;
    // --- Concat reassembly across separate webhook calls (refinement) ---
    public function isMultipart(): bool;
    /** @return string|null Shared reference across parts. */
    public function getConcatReference(): ?string;
    /** @return int|null 1-based part index. */
    public function getConcatPart(): ?int;
    /** @return int|null Total number of parts. */
    public function getConcatTotal(): ?int;
    /** @return array Raw payload. */
    public function getRawPayload(): array;
}
```

### 6.6 `Balance`

> Immutable account balance (amount + unit). Unit is an ISO-4217 currency or `'POINTS'` (Mitake `AccountPoint`). Composes `Money` semantics while still supporting non-currency point credit; optional auto-reload flag.

```php
<?php
namespace Psr\Sms;

use Psr\Sms\Exception\InvalidArgumentException;

final class Balance
{
    public readonly float $amount;

    /**
     * @param float|int|string $amount >= 0
     * @param string $unit ISO 4217 code or 'POINTS'.
     * @param bool $autoReload Whether the account auto-reloads credit.
     * @throws InvalidArgumentException
     */
    public function __construct(
        float|int|string $amount,
        public readonly string $unit = 'POINTS',
        public readonly bool $autoReload = false
    ) {
        if (!is_numeric($amount)) {
            throw new InvalidArgumentException('Balance amount must be numeric.');
        }
        if ($unit === '') {
            throw new InvalidArgumentException('Balance unit must be a non-empty string.');
        }
        $this->amount = (float) $amount;
    }
    public function getAmount(): float { return $this->amount; }
    public function getUnit(): string { return $this->unit; }
    public function isAutoReload(): bool { return $this->autoReload; }
}
```

---

## 7. Enumerations

These are string-backed `enum`s. They are type-safe by construction, so there is no `assertValid()` — pass the enum case directly, or normalise an external string via `DeliveryState::tryFrom()` / `from()`. To enumerate all cases use the built-in `DeliveryState::cases()`.

### 7.1 `DeliveryState`

> Canonical delivery-lifecycle states — the single normalisation target. Mitake mapping noted per case.

```php
<?php
namespace Psr\Sms;

enum DeliveryState: string
{
    case QUEUED = 'queued';            // no distinct Mitake source (Mitake 0 normalises to SCHEDULED)
    case SCHEDULED = 'scheduled';      // Mitake 0 (deferred / awaiting dlvtime)
    case SENDING = 'sending';
    case SENT = 'sent';                // Mitake 1,2 to carrier
    case ACCEPTED = 'accepted';
    case DELIVERED = 'delivered';      // Mitake 4; DELIVRD
    case UNDELIVERED = 'undelivered';  // network gave up; UNDELIV (no distinct Mitake numeric)
    case FAILED = 'failed';            // Mitake 5,6,7; SYNTAXE
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';          // Mitake 8; EXPIRED / UNDELIV
    case CANCELED = 'canceled';        // Mitake 9; DELETED
    case READ = 'read';                // RCS / WhatsApp
    case RECEIVING = 'receiving';
    case RECEIVED = 'received';
    case UNKNOWN = 'unknown';          // Mitake UNKNOWN

    /** @return bool Whether this is a terminal state. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::DELIVERED, self::UNDELIVERED, self::FAILED,
            self::REJECTED, self::EXPIRED, self::CANCELED => true,
            default => false,
        };
    }
}
```

### 7.2 `Encoding`

> On-air alphabet (GSM 03.38 / TS 23.038).

```php
<?php
namespace Psr\Sms;

enum Encoding: string
{
    case AUTO = 'auto';    // provider-side GSM-vs-Unicode detection (Vonage encoding_type=auto)
    case GSM7 = 'gsm7';
    case UCS2 = 'ucs2';
    case BINARY = 'binary';
}
```

### 7.3 `MessageType`

> Route-class.

```php
<?php
namespace Psr\Sms;

enum MessageType: string
{
    case TRANSACTIONAL = 'transactional';
    case PROMOTIONAL = 'promotional';
    case OTP = 'otp';
    case FLASH = 'flash';
}
```

---

## 8. Exception Hierarchy

> Following PSR-18: **marker interfaces** form the catch hierarchy and **concrete classes** extend SPL exceptions. The root marker `SmsExceptionInterface` extends `\Throwable` so callers can type-hint and `catch` the marker directly (the PSR-18 style); every concrete class still extends an SPL exception (`\RuntimeException`, `\InvalidArgumentException`, …) and so is a `\Throwable` at runtime. A conformant driver **MUST** throw only exceptions implementing `SmsExceptionInterface`.

### 8.1 Marker interfaces

```php
<?php
namespace Psr\Sms\Exception;

/** Marker implemented by every exception thrown by an SMS PSR impl.
 *  Extends \Throwable so callers can catch the marker directly; concrete
 *  classes MUST extend an SPL exception. */
interface SmsExceptionInterface extends \Throwable {}
```

```php
<?php
namespace Psr\Sms\Exception;

interface InvalidArgumentExceptionInterface extends SmsExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

/** Transport failure with no usable response (timeout/DNS/TLS/dropped socket).
 *  MAY be retryable. */
interface NetworkExceptionInterface extends SmsExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

/** Provider rejected the request because a rate / throughput / concurrency
 *  limit was exceeded (Twilio 20429 / HTTP 429, Mitake `l` too-many-connections).
 *  This is RETRYABLE after a back-off; the concrete class MAY expose a
 *  provider-supplied retry-after hint. */
interface RateLimitExceededExceptionInterface extends SmsExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

/** Rejected credentials/disabled account (Mitake e=bad auth, f=expired).
 *  Not retryable without re-credentialing. */
interface AuthenticationExceptionInterface extends SmsExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

/** Status query against unknown / already-consumed id (consume-once queues). */
interface UnknownMessageExceptionInterface extends SmsExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

/** Failed authenticity verification of an inbound DLR/MO callback.
 *  Impls MUST throw rather than trust unverified payloads. */
interface WebhookVerificationExceptionInterface extends SmsExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

/** OTP business failure (code expired, attempts exceeded) distinct from transport. */
interface VerificationExceptionInterface extends SmsExceptionInterface {}
```

### 8.2 Concrete classes (extend SPL)

```php
<?php
namespace Psr\Sms\Exception;

class InvalidArgumentException extends \InvalidArgumentException implements InvalidArgumentExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

class SmsException extends \RuntimeException implements SmsExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

class NetworkException extends \RuntimeException implements NetworkExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

/** Retryable rate/throughput/concurrency-limit failure. Carries an optional
 *  provider-supplied retry-after hint (seconds). */
class RateLimitExceededException extends \RuntimeException implements RateLimitExceededExceptionInterface
{
    /**
     * @param int|null $retryAfter Seconds to wait before retrying, or null if unknown.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return int|null Seconds to wait before retrying, or null if the provider gave no hint. */
    public function getRetryAfter(): ?int { return $this->retryAfter; }
}
```

```php
<?php
namespace Psr\Sms\Exception;

class AuthenticationException extends \RuntimeException implements AuthenticationExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

class UnknownMessageException extends \RuntimeException implements UnknownMessageExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

class WebhookVerificationException extends \RuntimeException implements WebhookVerificationExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

class VerificationException extends \RuntimeException implements VerificationExceptionInterface {}
```

```php
<?php
namespace Psr\Sms\Exception;

/** Thrown when a caller invokes an unsupported extension; demonstrates the
 *  SPL-extension pattern with \BadMethodCallException. */
class UnsupportedCapabilityException extends \BadMethodCallException implements SmsExceptionInterface {}
```

### 8.3 Catch semantics

A caller can:

- `catch (\Psr\Sms\Exception\NetworkExceptionInterface $e)` to retry transient failures.
- `catch (\Psr\Sms\Exception\RateLimitExceededExceptionInterface $e)` to back off (honouring `getRetryAfter()` when present) and retry.
- `catch (\Psr\Sms\Exception\AuthenticationExceptionInterface $e)` to halt and re-credential.
- `catch (\Psr\Sms\Exception\SmsExceptionInterface $e)` as the catch-all for anything this layer throws.

> **Catch ordering note.** `RateLimitExceededExceptionInterface` extends `SmsExceptionInterface` directly (it is *not* a `NetworkExceptionInterface`), so a caller that wants distinct back-off behaviour **MUST** place its `catch` before the generic `SmsExceptionInterface` clause.

---

## 9. Provider Coverage Matrix

`Y` = implements; `–` = does not; `feedback` = via `MessageFeedbackInterface`; `event` = via `EventDestinationParserInterface`.

| Provider | Client (core) | Capability (core) | Bulk | StatusQuery | ReceiptParser | Inbound | Schedulable | Balance | Verification | Notable extras |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **Twilio** | Y | Y | Y | Y | Y | Y | Y (msg-service) | – | Y (Verify) | Redactable, AsyncBulk, RCS |
| **Vonage Messages** | Y | Y | – | Y | Y | Y | – | – | Y (Verify v2) | multichannel |
| **Vonage SMS legacy** | Y | Y | – | Y | Y | Y | – | Y | – | inline price, binary, ms TTL, parts |
| **AWS SNS** | Y | Y | Y (topic) | – | – | – | – | – | – | TopicPublish, AccountPrefs |
| **AWS End User Msg** | Y | Y | Y | – (event) | – (event) | event | – | – | – | EventDestinationParser, MessageFeedback, MMS, max-price |
| **MessageBird** | Y | Y | Y | Y | Y | Y | Y | Y | Y | NumberLookup (HLR) |
| **Plivo** | Y | Y | Y | Y | Y | Y | Y | Y | – | sticky sender, MMS |
| **Telnyx** | Y | Y | – (loop) | Y | Y | Y | Y | – | – | TemplateRegistry (10DLC), MMS |
| **Sinch** | Y | Y | Y | Y | Y | Y | Y | – | – | personalization, MessageFeedback |
| **Infobip SMS** | Y | Y | Y | Y | Y | Y | Y | – | Y (2FA) | scheduling 180d |
| **Clickatell** | Y | Y | Y | Y | Y | Y | Y | Y | – | binary |
| **Gupshup** | Y | Y | Y | – | Y | Y | – | Y | Y | opt-in/opt-out |
| **MSG91** | Y | Y | Y | – | Y | Y | Y | Y | Y | personalization, short_url |
| **Kaleyra** | Y | Y | Y | – | Y | Y | Y | – | – | route classes |
| **Karix** | Y | Y | Y | – | Y | – | Y | – | Y | DLT fields, urltrack |
| **Africa's Talking** | Y | Y | Y | – | Y | Y | Y | Y | – | inline result, premium |
| **Termii** | Y | Y | Y | – | Y | – | Y | Y | Y | in-app token |
| **Kavenegar** | Y | Y | Y | – | – | Y (poll) | Y | Y | Y | personalization (sendarray) |
| **smsapi.com** | Y | Y | Y | – | Y | Y | Y | Y | – | fast=1 priority |
| **BulkSMS.com** | Y | Y | Y | – | Y | Y | Y | Y | – | routingGroup |
| **LINK Mobility** | Y | Y | Y | – | Y | Y | Y | Y | – | binary, premium |
| **Mitake (三竹)** | Y | Y | Y | Y | Y | – | Y | Y | – | inline AccountPoint/Duplicate |
| **Every8d** | Y | Y | Y | Y (poll) | – | – | Y | Y | – | inline result, MMS |
| **KotSMS** | Y | Y | Y | Y (poll) | – | – | Y | Y | – | vldtime |
| **HiNet/mPro** | Y | Y | – | Y (poll) | – | Y (poll) | – | – | – | persistent TCP socket |
| **Alibaba** | Y | Y | Y | – | Y | – | – | – | Y | template+sign (mandatory), TemplateRegistry |
| **Tencent** | Y | Y | Y | Y (poll) | Y | Y (poll) | – | – | Y | template+sign, CostReport |
| **SMPP 3.4** | Y | Y | Y | – (push) | Y (deliver_sm) | Y (deliver_sm) | Y | – | – | binary, UDH reassembly, keepalive |
| **SMPP 5.0** | Y | Y | Y | – | Y | Y | Y | – | – | cell broadcast, congestion |

---

## 10. Worked Concrete Mapping — Mitake (三竹) Driver

Mitake (三竹簡訊, Taiwan) HTTP API v2.14. POST to `https://{domain}/api/mtk/...`. Auth: `username` + `password` request params (no token). TLS 1.2+ required, max 15 concurrent connections.

### 10.1 Capability declaration

```php
<?php
namespace Acme\Sms\Mitake;

use Psr\Sms\CapabilityAwareInterface;

// supports() returns true for exactly these:
//   Capability::BULK              -> SmBulkSend (<=500/batch)
//   Capability::STATUS_QUERY      -> SmQuery (<=100 msgids)
//   Capability::DELIVERY_RECEIPT  -> GET callback to 'response' URL
//   Capability::SCHEDULE          -> dlvtime
//   Capability::CANCEL            -> SmCancel (<=100)
//   Capability::BALANCE           -> SmQuery without msgid -> AccountPoint
//   Capability::IDEMPOTENCY       -> clientid (12h dedup window)
//   Capability::FLASH             -> false (Mitake has no class-0 toggle)
//   Capability::INBOUND           -> false (one-way)
//   Capability::VERIFICATION      -> false (raw OTP via send() only)
```

So the Mitake driver implements: `SmsClientInterface`, `CapabilityAwareInterface`, `BulkSmsClientInterface`, `DeliveryStatusQueryInterface`, `DeliveryReceiptParserInterface`, `SchedulableSmsClientInterface`, `BalanceInterface`. It does **NOT** implement `InboundMessageReceiverInterface` or `VerificationInterface`.

### 10.2 Recipient normalisation (`PhoneNumber` → `dstaddr`)

Mitake expects national TW format (`0912345678`). The driver normalises before building `dstaddr`: strip leading `+`, strip `.`/`-`/space, rewrite `88609` → `8869`. Since `PhoneNumber` may arrive E.164 (`+886912345678`), the adapter converts `+886` → `0`.

```php
// inside MitakeClient
private function toDstaddr(\Psr\Sms\PhoneNumber $to): string
{
    $v = $to->getValue();                 // already display-stripped by VO
    $v = ltrim($v, '+');
    $v = str_replace(['.', '-', ' '], '', $v);
    if (strpos($v, '88609') === 0) { $v = '8869' . substr($v, 5); }
    if (strpos($v, '886') === 0)   { $v = '0' . substr($v, 3); }   // E.164 -> national
    return $v;
}
```

### 10.3 `send()` → `SmSend`

Maps `Message` to `SmSend` params. `CharsetURL = UTF8` is used so `smbody` is URL-encoded UTF-8 (Big5 is the wire default; UTF8 is selected explicitly).

| `Message` source | `SmSend` param | Notes |
| --- | --- | --- |
| (config) | `username`, `password` | request params; TLS 1.2+ |
| `getTo()` → §10.2 | `dstaddr` | normalised national number |
| `getBody()` | `smbody` | URL-encoded; `CharsetURL=UTF8` |
| `getSchedule()->format('YmdHis')` | `dlvtime` | `YYYYMMDDHHMMSS` (or seconds offset) |
| `getValidity()->toMinutes()` | `vldtime` | TTL |
| `getStatusCallbackUrl()` | `response` | DLR callback URL |
| `getIdempotencyKey()` | `clientid` | 12h dedup window |
| `getProviderOptions()['mitake.destname']` | `destname` | optional recipient name |
| `getProviderOptions()['mitake.objectID']` | `objectID` | optional |

`SmSend` returns key=value lines with `[n]` block headers: `msgid=#000000013`, `statuscode=1`, `AccountPoint=126`, `Duplicate`, `smsPoint`. The driver parses them into `SmsResult`:

| `SmSend` response | `SmsResultInterface` getter |
| --- | --- |
| `msgid` | `getMessageId()` |
| `statuscode` is a numeric code (not a letter) | `isAccepted()` → `true` |
| `statuscode` mapped via §10.7 | `getInlineStatus()->getState()` |
| `AccountPoint` | `getRemainingBalance()` → `new Balance($n, 'POINTS')` |
| `Duplicate == 'Y'` | `isDuplicate()` → `true` |
| `smsPoint` | `getPointsCost()` → `new PointsCost($n, 'POINTS')` (also retained in `getRawResponse()`) |

Letter `statuscode` values are errors and **MUST** be thrown: `e` → `AuthenticationException` (bad auth), `f` → `AuthenticationException` (expired account), `v` → `InvalidArgumentException` (invalid mobile), `u` → `InvalidArgumentException` (empty body), `y` → `InvalidArgumentException` (param error), `z` → `SmsException` (no data), `l` → `RateLimitExceededException` (too many connections — retryable; back off and retry).

### 10.4 `sendBulk()` → `SmBulkSend`

Mitake `SmBulkSend` packs fields with `$$` and records by newline (`\n`), up to **500** records per batch. The adapter chunks the `Message[]` into 500-record batches, builds `clientid$$dstaddr$$dlvtime$$vldtime$$destname$$response$$smbody` lines, preserves input order, and concatenates the per-batch results.

### 10.5 `getStatuses()` → `SmQuery` (with msgids)

`SmQuery` accepts up to **100** comma-separated msgids. The driver chunks transparently. Response is TAB-separated `msgid<TAB>statuscode<TAB>statustime`, lines CRLF-separated. Each line maps to a `DeliveryStatus` keyed by `msgid`, with `statuscode` normalised via §10.7 and `statustime` parsed into a `\DateTime`. A queried id absent from the response → `UnknownMessageException`.

### 10.6 `getBalance()` → `SmQuery` (without msgid)

Calling `SmQuery` **without** a `msgid` param returns the account balance as `AccountPoint=NNN`. The driver maps it to `new Balance($n, 'POINTS')`.

### 10.7 `parseReceipt()` + `getAcknowledgement()` — DLR callback

Mitake POSTs nothing; it issues an HTTP **GET** to the client's `response` URL with query params: `msgid`, `dstaddr`, `dlvtime`, `donetime`, `statuscode`, `statusstr`, `StatusFlag`. The driver reads them from `$serverParams` (`$_GET`/`$_SERVER`) and builds a `DeliveryStatus`.

Status normalisation (`statuscode` / `StatusFlag` and `statusstr` → `DeliveryState`):

| Mitake code | `statusstr` | `DeliveryState` | final? |
| --- | --- | --- | --- |
| `0` | (scheduled) | `SCHEDULED` | no |
| `1`, `2` | (to carrier) | `SENT` | no |
| `4` | `DELIVRD` | `DELIVERED` | yes |
| `5` | `SYNTAXE` (content error) | `FAILED` | yes |
| `6` | (bad number) | `FAILED` | yes |
| `7` | (disabled) | `FAILED` | yes |
| `8` | `EXPIRED` / `UNDELIV` | `EXPIRED` | yes |
| `9` | `DELETED` | `CANCELED` | yes |
| (other) | `UNKNOWN` | `UNKNOWN` | no |

The client **MUST** reply HTTP 200, `text/plain`, body exactly `"magicid=sms_gateway_rpack\nmsgid=NNN\n"` — produced by `getAcknowledgement()`:

```php
public function getAcknowledgement(\Psr\Sms\DeliveryStatusInterface $status): ?string
{
    return "magicid=sms_gateway_rpack\nmsgid=" . $status->getMessageId() . "\n";
}
```

### 10.8 `schedule()` / `cancel()` → `dlvtime` / `SmCancel`

`schedule()` requires `getSchedule()` to be non-null; it formats `dlvtime` via `Schedule::format('YmdHis')` and calls `SmSend`. `cancel()` calls `SmCancel` (up to 100 msgids); response is `msgid=statuscode` per line. A `statuscode` of `9` (cancelled) → `true`; anything indicating the message already dispatched → `false`.

### 10.9 Method-by-method summary

| PSR-Sms method | Mitake endpoint | Cap / limit |
| --- | --- | --- |
| `SmsClientInterface::send()` | `SmSend` | single |
| `BulkSmsClientInterface::sendBulk()` | `SmBulkSend` | 500/batch, `$$` + `\n` |
| `DeliveryStatusQueryInterface::getStatuses()` | `SmQuery` (msgids) | 100/call, TAB-sep |
| `DeliveryStatusQueryInterface::getStatus()` | `SmQuery` (1 msgid) | — |
| `BalanceInterface::getBalance()` | `SmQuery` (no msgid) | → `AccountPoint=NNN` |
| `SchedulableSmsClientInterface::schedule()` | `SmSend` + `dlvtime` | `YYYYMMDDHHMMSS` |
| `SchedulableSmsClientInterface::cancel()` | `SmCancel` | 100/call |
| `DeliveryReceiptParserInterface::parseReceipt()` | inbound GET callback | params via `$_GET` |
| `DeliveryReceiptParserInterface::getAcknowledgement()` | reply body | `magicid=...` |

---

## 11. Compliance & Edge-Case Appendix

### 11.1 GSM 03.38 segmentation (normative for segment counting)

Drivers **SHOULD** compute segment count even when the provider does not return one, because billing is per segment.

- **GSM-7 alphabet:** 160 chars per single SMS; 153 per concatenated part (7 bytes of the 140-byte payload go to the UDH).
- **Extension characters** `^ { } \ [ ~ ] | €` (the euro sign is a GSM-7 extension char) each consume **2** GSM-7 septets (they need a `0x1B` escape). A driver counting GSM-7 length **MUST** count each extension char as 2.
- **UCS-2 (non-GSM-7 present):** a single non-GSM-7 character upgrades the *whole* message to UCS-2 → 70 chars single, 67 per concatenated part.
- **Encoding `AUTO`:** the driver detects GSM-7 vs UCS-2 by scanning the body against the GSM-7 + extension table; any miss forces UCS-2.
- **Mitake specifics:** long/concatenated SMS requires explicit account permission; without it, a body exceeding one short SMS is **truncated** to a single segment. A Mitake driver **SHOULD** warn (or refuse, via `InvalidArgumentException`) when a body exceeds one segment and concatenation is not enabled.

Reference GSM-7 length helper:

```php
<?php
namespace Psr\Sms;

/** Illustrative, non-normative GSM-7 length helper. */
final class Gsm7
{
    private function __construct() {}

    /**
     * Returns the GSM-7 septet count, with extension chars counted as 2,
     * or -1 when the text is not GSM-7 representable (caller MUST use UCS-2).
     */
    public static function length(string $text): int
    {
        $base = ' @£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞ'
              . 'ÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
              . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        $ext = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];
        $count = 0;
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            if (in_array($ch, $ext, true)) { $count += 2; continue; }
            if (mb_strpos($base, $ch, 0, 'UTF-8') !== false) { $count += 1; continue; }
            return -1; // not GSM-7 representable -> caller MUST use UCS-2
        }
        return $count;
    }
}
```

### 11.2 E.164 & national formats

- E.164: `+` followed by a leading non-zero digit, total **1–15** digits. `PhoneNumber` enforces `^\+[1-9]\d{1,14}$` after stripping display notation.
- TW national: `09xxxxxxxx` (validated as 4–15 digits in `FORMAT_NATIONAL`). Mitake consumes national; the adapter converts E.164 `+886…` → `0…`.
- Short codes are **not** E.164 — use `FORMAT_SHORTCODE`. A driver **MUST NOT** reject a valid short code for failing E.164.
- SMPP carries bare digits + TON/NPI; an SMPP driver maps `PhoneNumber` to the right TON/NPI rather than forcing `+`.

### 11.3 STOP / opt-out

- Opt-out is enforced as **application state**, not a wire call on most providers. The inbound path surfaces it: `InboundMessageInterface::getKeyword()` returns `STOP`/`UNSUBSCRIBE`/`CANCEL`/`END`/`QUIT` (immediate, permanent) or `HELP`/`INFO`.
- Where the provider auto-handles opt-out (AWS), the driver **SHOULD** still expose the keyword so the application can keep its own suppression list.
- A caller **MUST NOT** send to a number it has recorded as opted-out, regardless of provider behaviour (TCPA/GDPR obligation).

### 11.4 Sender-ID rules

- Alphanumeric sender IDs are limited to 11 chars and are reply-incapable; `Sender::fromAlphanumeric()` enforces the length.
- In regulated markets (US/CA), dynamic alphanumeric IDs are unsupported; the provider silently overrides or blocks. A driver **SHOULD** prefer `Sender::fromMessagingService()`/`fromPool()` there.
- Twilio scheduling **MUST** use a messaging-service sender; `SchedulableSmsClientInterface::schedule()` throws `InvalidArgumentException` otherwise.

### 11.5 Idempotency & correlation

- Two distinct concepts: **idempotency key** (`getIdempotencyKey()`, dedup of retries — Twilio `Idempotency-Key`, Mitake `clientid` with a 12-hour window returning `Duplicate=Y`) versus **client reference** (`getClientRef()`, a correlation tag echoed on the DLR — Vonage `client_ref`, ≤100 chars).
- A Mitake driver maps `getIdempotencyKey()` → `clientid` and surfaces `Duplicate=Y` via `SmsResult::isDuplicate()`. Resending the same `clientid` inside 12h does **not** create a new dispatch.
- Where a provider lacks native idempotency, the driver **MAY** emulate it client-side using the key, but **MUST** advertise `Capability::IDEMPOTENCY = false` if it cannot honour the dedup contract.

### 11.6 Webhook authenticity

- Parsers **MUST** verify authenticity before trusting a callback, throwing `WebhookVerificationException` on failure. Verification is injected via `SignatureVerifierInterface` constructed with secret/algorithm/skew.
- Mitake's GET callback has no signature; a Mitake deployment **SHOULD** rely on a secret path/allow-list and the driver **MAY** treat absence of a verifier as "trust the configured endpoint" — but **MUST** document that choice.

### 11.7 Bifurcated payload (body XOR template)

`Message` enforces the XOR: `withBody()` clears any template; `withTemplate()` clears the body. China clouds (Alibaba/Tencent) and India DLT require the template path with `signName` + params and the matching `ComplianceFields` (entity/template ids). A driver targeting those routes **MUST** reject a free-form body via `InvalidArgumentException` when the destination requires an approved template.

---

*End of specification.*
