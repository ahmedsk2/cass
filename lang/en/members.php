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

    'page' => [
        'title' => 'Team',
        'subheading' => 'Everyone who can work on this organization\'s conferences, and everyone who has been invited.',
    ],

    'columns' => [
        'name' => 'Name',
        'role' => 'Role',
        'state' => 'Status',
        'joined' => 'Joined',
        'notify' => 'New-abstract emails',
    ],

    'state' => [
        'member' => 'Member',
        'invited' => 'Invited',
        'expired' => 'Invitation expired',
    ],

    'notify' => [
        'on' => 'On',
        'off' => 'Off',
    ],

    'fields' => [
        'email' => 'Email address',
        'role' => 'Role',
        // Deliberately lower-case "owner": this helper text is rendered inside
        // the invite modal, and MembersPageTest asserts that an admin's modal
        // does not contain the string OrganizationRole::Owner->getLabel() -
        // a case-sensitive substring check that a capitalised "Owners" here
        // would satisfy for the wrong reason, hiding a roleOptions() that had
        // stopped filtering.
        'role_help' => 'An owner or an admin manages the team and the organization profile. A member creates conferences, invites reviewers and reads submissions.',
    ],

    'actions' => [
        'invite' => 'Invite someone',
        'invite_heading' => 'Invite someone to this organization',
        'invite_description' => 'They get an email with a link that works for 14 days. If they already have a CASS account, the link signs them in and adds them.',
        'change_role' => 'Change role',
        'change_role_heading' => 'Change the role of :name',
        'remove' => 'Remove',
        'remove_heading' => 'Remove :name?',
        'remove_description' => 'They lose access to this organization straight away. Their CASS account and anything they created stay exactly as they are, and you can invite them again later.',
        'notifications_on' => 'Email me about new abstracts',
        'notifications_off' => 'Stop emailing me about new abstracts',
        'resend' => 'Resend',
        'resend_heading' => 'Send the invitation again?',
        'resend_description' => 'A new link is emailed. **Any link they already have stops working**, which is the point when a link was lost.',
        'revoke' => 'Withdraw',
        'revoke_heading' => 'Withdraw this invitation?',
        'revoke_description' => 'The link stops working. If they follow it they are told the invitation was withdrawn.',
    ],

    'notices' => [
        'invited' => 'Invitation sent',
        'invited_body' => 'A link is on its way to :email.',
        'role_changed' => 'Role updated',
        'removed' => 'Removed from the organization',
        'notifications_saved' => 'Preference saved',
        'resent' => 'Invitation sent again',
        'revoked' => 'Invitation withdrawn',
        'refused' => 'Nothing changed',
    ],

    'errors' => [
        'not_allowed' => 'Only an owner or an admin can manage the team.',
        'not_a_member' => 'That person is not a member of this organization.',
        'own_role' => 'You cannot change your own role. Ask another owner.',
        'own_membership' => 'You cannot remove yourself. Ask another owner.',
        'own_notifications' => 'You can only change your own email preference.',
        'owner_grants_owner' => 'Only an owner can make somebody else an owner.',
        'owner_only_changes_owner' => 'Only an owner can change or remove another owner.',
        'last_owner' => 'An organization always needs at least one owner. Make somebody else an owner first.',
        'already_a_member' => ':email is already a member of this organization.',
        'bad_email' => 'That does not look like an email address.',
    ],

    'mail' => [
        'subject' => 'You have been invited to join :organization on CASS',
        'greeting' => 'Hello,',
        'intro' => ':inviter has invited you to join **:organization** on CASS as :role. CASS is where they collect and review conference abstracts.',
        'action' => 'Accept the invitation',
        'expiry' => 'This link works for :days days. After that, ask them to send a new one.',
        'ignore' => 'If you were not expecting this, you can ignore this email and nothing happens.',
    ],

];
