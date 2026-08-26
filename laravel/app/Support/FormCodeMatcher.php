<?php

namespace App\Support;

class FormCodeMatcher
{
    /**
     * Whether a submitted form code refers to the same form as a stored
     * forms.form_code value.
     *
     * forms.form_code is "slug-suffix", fixed at creation, where the slug
     * is truncated to a short max length (see
     * LegacyFormWriteController::generateFormCodeWithSlug). The frontend
     * rebuilds share URLs from the form's CURRENT, untruncated title every
     * time one is generated - so for any title whose slug exceeds that
     * truncation length (nearly all real titles), a freshly-built link's
     * slug never exactly matches the stored one, even though it's the same
     * form. The same divergence happens if a title is edited after
     * creation. Either way, the suffix - the actual unguessable, unique
     * part - stays the same, so that's what's compared.
     */
    public static function matches(string $submittedCode, string $storedFormCode): bool
    {
        if ($submittedCode === '' || $storedFormCode === '') {
            return false;
        }
        if (hash_equals($storedFormCode, $submittedCode)) {
            return true;
        }

        $submittedParts = explode('-', $submittedCode);
        $submittedSuffix = end($submittedParts);
        $storedParts = explode('-', $storedFormCode);
        $storedSuffix = end($storedParts);

        return $submittedSuffix !== '' && hash_equals($storedSuffix, $submittedSuffix);
    }
}
