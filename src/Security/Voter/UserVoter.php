<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Only admins manage users. Nobody can delete, deactivate or demote their
 * own account, which also guarantees that at least one admin always remains.
 *
 * @extends Voter<string, User|null>
 */
final class UserVoter extends Voter
{
    public const MANAGE = 'USER_MANAGE';
    public const EDIT = 'USER_EDIT';
    /** Change the role or the active flag of an account. */
    public const CHANGE_ACCESS = 'USER_CHANGE_ACCESS';
    public const DELETE = 'USER_DELETE';

    public function __construct(private readonly AccessDecisionManagerInterface $decisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::MANAGE => true,
            self::EDIT, self::CHANGE_ACCESS, self::DELETE => $subject instanceof User,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$this->decisionManager->decide($token, ['ROLE_ADMIN'])) {
            return false;
        }

        if (self::CHANGE_ACCESS === $attribute || self::DELETE === $attribute) {
            \assert($subject instanceof User);

            // A new account (no id yet) is never the current user.
            return null === $subject->getId() || $subject->getId() !== $user->getId();
        }

        return true;
    }
}
