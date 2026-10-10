<?php

namespace App\DragDrop;

/**
 * Moves a node of a tree to another parent and position - the server side of
 * every tree that is arranged by dragging.
 *
 * The browser shows a move at once, but what counts is checked here: a node
 * never becomes its own descendant, the tree never grows deeper than
 * allowed, and afterwards the siblings are numbered 0, 1, 2 ... without gaps.
 */
final class TreeMover
{
    /**
     * @template T of TreeNode
     *
     * @param T           $node
     * @param T|null      $newParent   null for the top level
     * @param int         $position    wanted place among the new siblings, 0 is first; too large means "last"
     * @param iterable<T> $siblings    the nodes that are children of $newParent now (with or without $node)
     * @param iterable<T> $oldSiblings the nodes that share the current parent of $node, when that parent changes
     * @param int         $maxDepth    deepest allowed level, 1 being the top level; 0 for no limit
     * @param int         $height      levels of the subtree that moves along: 1 for a node without children
     *
     * @throws InvalidMoveException
     */
    public function move(TreeNode $node, ?TreeNode $newParent, int $position, iterable $siblings, iterable $oldSiblings = [], int $maxDepth = 0, int $height = 1): void
    {
        $depth = 1;
        for ($ancestor = $newParent; null !== $ancestor; $ancestor = $ancestor->getParent()) {
            if ($ancestor === $node || (null !== $node->getId() && $ancestor->getId() === $node->getId())) {
                throw new InvalidMoveException(InvalidMoveException::INTO_ITSELF);
            }
            if (++$depth > 1000) {
                // A loop in stored data must not hang the request.
                throw new InvalidMoveException(InvalidMoveException::INTO_ITSELF);
            }
        }

        if ($maxDepth > 0 && $depth + $height - 1 > $maxDepth) {
            throw new InvalidMoveException(InvalidMoveException::TOO_DEEP);
        }

        $changesParent = $node->getParent() !== $newParent;

        $ordered = [];
        foreach ($siblings as $sibling) {
            if ($sibling !== $node) {
                $ordered[] = $sibling;
            }
        }
        usort($ordered, static fn (TreeNode $a, TreeNode $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);
        array_splice($ordered, max(0, min($position, \count($ordered))), 0, [$node]);

        $node->setParent($newParent);
        foreach ($ordered as $index => $sibling) {
            $sibling->setPosition($index);
        }

        if ($changesParent) {
            // Close the gap the node left behind.
            $remaining = [];
            foreach ($oldSiblings as $sibling) {
                if ($sibling !== $node) {
                    $remaining[] = $sibling;
                }
            }
            usort($remaining, static fn (TreeNode $a, TreeNode $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);
            foreach ($remaining as $index => $sibling) {
                $sibling->setPosition($index);
            }
        }
    }
}
