<?php

namespace App\Enums;

enum SendOutcome: string
{
    /** The transport accepted the message and returned an external id. */
    case Accepted = 'accepted';

    /** The transport definitively refused the message; it was not sent. */
    case Rejected = 'rejected';

    /** The request may or may not have been sent (e.g. timeout after dispatch). */
    case Unknown = 'unknown';
}
