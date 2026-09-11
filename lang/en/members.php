<?php

declare(strict_types=1);

/*
 * The organizer's Members page and the whole public /invite/{token} page. Spec
 * section 10: locale `en` only in v1, with every string here so Arabic is a
 * copy of this file and not a branch in a Blade view.
 *
 * The `invite.*` group is shared by member *and* reviewer invitations, because
 * one page serves both; `lang/en/reviewer.php` carries only the two lines where
 * the wording really differs.
 */

return [

    'invite' => [
        'title' => 'Your invitation',
        'headline' => ':organization has invited you to join their team on CASS as :role.',
        'addressed_to' => 'This invitation was sent to :email.',
        'expires_on' => 'This link works until :date.',

        'accept' => 'Accept and join',
        'sign_in' => 'Confirm your password to accept',
        'sign_in_help' => 'You already have a CASS account at this address. Confirm your password to accept; you will be asked to sign in afterwards.',
        'sign_in_and_accept' => 'Accept invitation',
        'create_account' => 'Create your account',
        'create_account_help' => 'You do not have a CASS account yet. Choose a password and you are in - there is no separate email to confirm, because you just followed the link we sent you.',
        'create_account_and_accept' => 'Create account and accept',
        'name' => 'Full name',
        'password' => 'Password',
        'password_help' => 'At least 10 characters with letters and numbers.',
        'password_confirmation' => 'Confirm password',
        'signed_in_as' => 'You are signed in as :email.',
        'sign_out' => 'Sign out and try again',
        'wrong_account' => 'You are signed in as :email, which is not the address this invitation was sent to. Sign out and follow the link again.',
        'wrong_password' => 'That password is not right.',
        'too_many_attempts' => 'Too many attempts. Please wait a minute and try again.',

        'expired' => 'This invitation has expired. Ask whoever invited you to send a new one.',
        'revoked' => 'This invitation has been withdrawn. Ask whoever invited you if you think that is a mistake.',
        'already_accepted' => 'This invitation has already been used. Sign in to your account to continue.',

        'blocked' => [
            'expired' => 'This invitation has expired.',
            'revoked' => 'This invitation has been withdrawn.',
            'accepted' => 'This invitation has already been used.',
            'pending' => 'This invitation cannot be accepted right now.',
            'wrong_account' => 'This invitation was sent to :email. Sign out and follow the link again from that account.',
            'not_signed_in' => 'Sign in first, then accept the invitation.',
            'account_exists' => 'There is already a CASS account at this address. Sign in instead.',
            'inviter_gone' => 'Whoever invited you no longer manages this organization. Ask them to invite you again.',
        ],
    ],

];
