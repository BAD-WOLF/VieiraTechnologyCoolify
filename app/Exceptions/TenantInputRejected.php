<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A value a platform tenant may not save (see TenantInputGuard). It is a validation error, so
 * forms show it next to their fields; the platform also shows it as a notification, because the
 * field is often not on the form that saved it (a parsed compose file, an import...).
 */
class TenantInputRejected extends ValidationException {}
