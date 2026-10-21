<?php

declare(strict_types=1);

namespace App\Data\Teams;

use App\Enums\TeamRole;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\RequiredWithout;
use Spatie\LaravelData\Data;

/**
 * "Invite people" (docs/design/flows.md §16): one address in `email`, or
 * several in `emails` (separated by commas, spaces or new lines). Each one
 * becomes its own invitation through InviteMember.
 */
final class InviteMembersData extends Data
{
    public const int MAX_ADDRESSES = 20;

    public function __construct(
        public TeamRole $role,
        #[RequiredWithout('emails'), Email, Max(255)]
        public ?string $email = null,
        #[RequiredWithout('email'), Max(6000)]
        public ?string $emails = null,
    ) {}

    /**
     * The addresses to invite, lower-cased and de-duplicated.
     *
     * @return list<string>
     *
     * @throws ValidationException when an address is malformed or there are too many
     */
    public function addresses(): array
    {
        $field = $this->emails !== null ? 'emails' : 'email';
        $parts = preg_split('/[\s,;]+/u', (string) ($this->emails ?? $this->email), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $addresses = array_values(array_unique(array_map(static fn (string $part): string => Str::lower(trim($part, " \t<>\"'")), $parts)));

        if ($addresses === []) {
            throw ValidationException::withMessages([$field => 'Enter at least one email address.']);
        }

        if (count($addresses) > self::MAX_ADDRESSES) {
            throw ValidationException::withMessages([$field => 'Invite at most '.self::MAX_ADDRESSES.' people at once.']);
        }

        foreach ($addresses as $address) {
            if (mb_strlen($address) > 255 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages([$field => "{$address} isn’t an email address."]);
            }
        }

        return $addresses;
    }
}
