<?php

declare(strict_types=1);

namespace App\Legal;

/**
 * Versions of the legal texts (templates/legal/pages/). Changing TERMS_VERSION asks every producer
 * to accept the terms again before reaching /pro (TermsAcceptanceSubscriber), and every customer to
 * tick the box again at their next order. Change the matching date with the text.
 */
final class LegalDocuments
{
    /** Terms of use (both parts), with the data processing agreement and the withdrawal form. */
    public const string TERMS_VERSION = '1.0';

    public const string TERMS_UPDATED_AT = '2026-11-10';

    public const string PRIVACY_VERSION = '1.0';

    public const string PRIVACY_UPDATED_AT = '2026-11-10';

    public const string NOTICE_VERSION = '1.0';

    public const string NOTICE_UPDATED_AT = '2026-11-10';

    public const string COMMITMENTS_VERSION = '1.0';

    public const string COMMITMENTS_UPDATED_AT = '2026-11-10';
}
