<?php

namespace App\Security\Voter;

use App\Entity\Page;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Pages are managed by editors and admins. Drafts are visible to any logged-in user.
 *
 * @extends Voter<string, Page|null>
 */
final class PageVoter extends Voter
{
    public const VIEW = 'PAGE_VIEW';
    public const MANAGE = 'PAGE_MANAGE';

    public function __construct(private readonly AccessDecisionManagerInterface $decisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return (self::VIEW === $attribute && $subject instanceof Page)
            || (self::MANAGE === $attribute && (null === $subject || $subject instanceof Page));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (self::VIEW === $attribute) {
            \assert($subject instanceof Page);

            return $subject->isPublic() || $token->getUser() instanceof User;
        }

        return $this->decisionManager->decide($token, ['ROLE_EDITOR']);
    }
}
