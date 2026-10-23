<?php

declare(strict_types=1);

namespace App\Shared\Outbox\Handlers;

use App\Models\DocumentFile;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends an outbound email and records evidence in email_message.
 *
 * Idempotent via (company_id, idempotency_key). Re-processing a completed
 * message is a no-op.
 *
 * EMAIL_MODE (env / config): log | smtp | ses
 *   - log: write to log only (dev / CI)
 *   - smtp / ses: use Laravel mailer
 *
 * event_type: email.send
 * payload keys: companyId, to, subject, body|templateKey, entityType, entityId,
 *               idempotencyKey (recommended), documentFileId (optional), requestId
 */
final class EmailSendHandler
{
    public function __invoke(array $payload): void
    {
        $companyId = (string) ($payload['companyId'] ?? $payload['company_id'] ?? '');
        $to = (string) ($payload['to'] ?? $payload['to_address'] ?? '');
        $subject = (string) ($payload['subject'] ?? '');
        $idempotencyKey = (string) ($payload['idempotencyKey'] ?? $payload['idempotency_key'] ?? '');

        if ($companyId === '' || $to === '' || $subject === '') {
            throw new \InvalidArgumentException(
                'EmailSendHandler requires companyId, to and subject in payload.'
            );
        }

        if ($idempotencyKey === '') {
            // Derive a stable key when caller omitted one.
            $idempotencyKey = hash('sha256', implode('|', [
                $companyId,
                $to,
                $subject,
                (string) ($payload['entityId'] ?? $payload['entity_id'] ?? ''),
                (string) ($payload['requestId'] ?? $payload['request_id'] ?? ''),
            ]));
        }

        $existing = EmailMessage::query()
            ->where('company_id', $companyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null && in_array($existing->status, ['sent', 'skipped'], true)) {
            Log::info('EmailSendHandler: already sent/skipped, no-op', [
                'email_message_id' => $existing->id,
                'status' => $existing->status,
            ]);

            return;
        }

        $mode = (string) config('omnis.email.mode', env('EMAIL_MODE', 'log'));
        $body = (string) ($payload['body'] ?? $payload['htmlBody'] ?? '');
        $templateKey = $payload['templateKey'] ?? $payload['template_key'] ?? null;
        $entityType = $payload['entityType'] ?? $payload['entity_type'] ?? null;
        $entityId = $payload['entityId'] ?? $payload['entity_id'] ?? null;
        $documentFileId = $payload['documentFileId'] ?? $payload['document_file_id'] ?? null;
        $requestId = $payload['requestId'] ?? $payload['request_id'] ?? null;
        $cc = $payload['cc'] ?? $payload['cc_address'] ?? null;

        $messageId = $existing?->id ?? (string) Str::uuid();

        if ($existing === null) {
            EmailMessage::query()->create([
                'id' => $messageId,
                'company_id' => $companyId,
                'to_address' => $to,
                'cc_address' => $cc,
                'subject' => $subject,
                'template_key' => $templateKey,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'status' => 'queued',
                'document_file_id' => $documentFileId,
                'request_id' => $requestId,
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        try {
            if ($mode === 'log') {
                Log::info('EmailSendHandler [log mode]', [
                    'to' => $to,
                    'subject' => $subject,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'body_preview' => mb_substr($body, 0, 200),
                ]);
            } else {
                $this->deliver($to, $cc, $subject, $body, $documentFileId);
            }

            EmailMessage::query()->where('id', $messageId)->update([
                'status' => 'sent',
                'sent_at' => now(),
                'last_error' => null,
                'provider_message_id' => $mode === 'log' ? 'log:'.Str::uuid() : null,
            ]);
        } catch (\Throwable $e) {
            EmailMessage::query()->where('id', $messageId)->update([
                'status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }
    }

    private function deliver(string $to, mixed $cc, string $subject, string $body, mixed $documentFileId): void
    {
        $apiKey = (string) (env('RESEND_API_KEY') ?: env('MAIL_PASSWORD'));
        $fromAddress = (string) config('mail.from.address', 'noreply@omnisdos.com');
        $fromName = (string) config('mail.from.name', 'OMNIS');

        $payload = [
            'from' => $fromName !== '' ? "{$fromName} <{$fromAddress}>" : $fromAddress,
            'to' => [$to],
            'subject' => $subject,
            'text' => $body !== '' ? $body : $subject,
        ];
        if ($cc) {
            $payload['cc'] = is_array($cc) ? $cc : [$cc];
        }

        $document = $documentFileId
            ? DocumentFile::query()->where('id', $documentFileId)->first()
            : null;
        if ($document !== null && Storage::disk('local')->exists($document->storage_key)) {
            $payload['attachments'] = [[
                'filename' => $document->original_name ?: 'document.pdf',
                'content' => base64_encode((string) Storage::disk('local')->get($document->storage_key)),
            ]];
        }

        $response = Http::withToken($apiKey)
            ->timeout(20)
            ->post('https://api.resend.com/emails', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Resend API '.$response->status().': '.$response->body());
        }
    }
}
