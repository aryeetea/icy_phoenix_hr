<?php
// auth_helpers.php
//
// Helpers for generating Icy Phoenix login emails + passwords.

/**
 * Build Icy Phoenix login email:
 *   emp_no + first-letter(first_name) + first-letter(last_name) + @icyphoenix.local
 * Example: 10001, "Nova", "Blaze" -> 10001nb@icyphoenix.local
 */
function ipx_make_login_email(string $emp_no, string $first_name, string $last_name): string
{
    $first = strtolower(trim($first_name));
    $last  = strtolower(trim($last_name));

    $user_part = $emp_no;

    if ($first !== '') {
        $user_part .= substr($first, 0, 1);
    }
    if ($last !== '') {
        $user_part .= substr($last, 0, 1);
    }

    return $user_part . '@icyphoenix.local';
}

/**
 * Build Icy Phoenix login password (plain text BEFORE hashing):
 *   first-letter(first_name) + emp_no
 * Example: 10001, "Nova" -> n10001
 *
 * You must hash this before storing in the database.
 */
function ipx_make_login_password(string $emp_no, string $first_name): string
{
    $first  = strtolower(trim($first_name));
    $prefix = $first !== '' ? substr($first, 0, 1) : 'x'; // fallback 'x' if name missing
    return $prefix . $emp_no;
}