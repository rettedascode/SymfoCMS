<?php

namespace App\Security\Voter;

use App\Entity\Post;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Service\Settings;
use App\Workflow\ReviewWorkflow;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Editors and admins manage every post. Authors may create posts and
 * edit or delete only the ones they wrote. When the website requires a
 * review, a post that is public is out of its author's hands.
 *
 * @extends Voter<string, Post|null>
 */
final class PostVoter extends Voter
{
    public const VIEW = 'POST_VIEW';
    public const CREATE = 'POST_CREATE';
    public const EDIT = 'POST_EDIT';
    public const DELETE = 'POST_DELETE';
    /** Edit posts of other people and reassign the author. */
    public const MANAGE_ALL = 'POST_MANAGE_ALL';

    public function __construct(
        private readonly AccessDecisionManagerInterface $decisionManager,
        private readonly Settings $settings,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::VIEW, self::EDIT, self::DELETE => $subject instanceof Post,
            self::CREATE, self::MANAGE_ALL => true,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (self::VIEW === $attribute) {
            \assert($subject instanceof Post);

            return $subject->isPublic() || $user instanceof User;
        }

        if (!$user instanceof User) {
            return false;
        }

        if ($this->decisionManager->decide($token, ['ROLE_EDITOR'])) {
            return true;
        }

        return match ($attribute) {
            self::CREATE => $this->decisionManager->decide($token, ['ROLE_AUTHOR']),
            self::EDIT, self::DELETE => $subject instanceof Post
                && null !== $subject->getAuthor()
                && $subject->getAuthor()->getId() === $user->getId()
                // Otherwise an author could change approved text without anybody looking at it again.
                && !(ContentStatus::Published === $subject->getStatus() && true === $this->settings->get(ReviewWorkflow::REQUIRED_SETTING)),
            default => false,
        };
    }
}
