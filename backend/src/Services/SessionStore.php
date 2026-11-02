<?php
declare(strict_types=1);
namespace SpiceMama\Services;

/** Session persistence seam: native PHP sessions in production, arrays in tests. */
interface SessionStore
{
    /** Idempotent; starts or resumes the session. */
    public function start(): void;

    public function &data(): array;

    /** New session ID, old session deleted (fixation defence). */
    public function regenerate(): void;

    /** Clears server state and expires the cookie. */
    public function destroy(): void;
}
