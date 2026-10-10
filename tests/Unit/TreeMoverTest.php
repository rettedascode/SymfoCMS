<?php

namespace App\Tests\Unit;

use App\DragDrop\InvalidMoveException;
use App\DragDrop\MoveRequest;
use App\DragDrop\TreeMover;
use App\DragDrop\TreeNode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class TreeMoverTest extends TestCase
{
    public function testReordersSiblingsWithoutGaps(): void
    {
        [$a, $b, $c, $d] = $nodes = $this->nodes(4);

        (new TreeMover())->move($d, null, 1, $nodes);
        self::assertSame(['a' => 0, 'd' => 1, 'b' => 2, 'c' => 3], $this->positions($nodes));

        // A position beyond the end means "last".
        (new TreeMover())->move($a, null, 99, $nodes);
        self::assertSame(['d' => 0, 'b' => 1, 'c' => 2, 'a' => 3], $this->positions($nodes));

        (new TreeMover())->move($c, null, 0, $nodes);
        self::assertSame(['c' => 0, 'd' => 1, 'b' => 2, 'a' => 3], $this->positions($nodes));
        self::assertNull($b->getParent());
    }

    public function testMovesIntoAnotherParentAndClosesTheGap(): void
    {
        [$a, $b, $c] = $top = $this->nodes(3);
        $child = $this->node(10, 'x', 0, $a);

        (new TreeMover())->move($b, $a, 0, [$child], $top);

        self::assertSame($a, $b->getParent());
        self::assertSame(['b' => 0, 'x' => 1], $this->positions([$b, $child]));
        self::assertSame(['a' => 0, 'c' => 1], $this->positions([$a, $c]), 'The old siblings move up.');
    }

    public function testNothingMovesIntoItselfOrItsDescendants(): void
    {
        [$a, $b] = $this->nodes(2);
        $child = $this->node(10, 'child', 0, $a);
        $grandchild = $this->node(11, 'grandchild', 0, $child);

        foreach ([$a, $child, $grandchild] as $target) {
            try {
                (new TreeMover())->move($a, $target, 0, []);
                self::fail('Moving a node below '.$target->name.' must be refused.');
            } catch (InvalidMoveException $exception) {
                self::assertSame(InvalidMoveException::INTO_ITSELF, $exception->getMessage());
            }
        }

        self::assertNull($a->getParent(), 'A refused move changes nothing.');
        self::assertSame(0, $a->getPosition());
        self::assertSame(1, $b->getPosition());
    }

    public function testDepthIsLimited(): void
    {
        [$a, $b] = $this->nodes(2);
        $child = $this->node(10, 'child', 0, $a);

        // Two levels allowed: below a top-level node is fine, below its child is not.
        (new TreeMover())->move($b, $a, 0, [$child], maxDepth: 2);
        self::assertSame($a, $b->getParent());

        $c = $this->node(3, 'c', 0);
        $this->expectException(InvalidMoveException::class);
        $this->expectExceptionMessage(InvalidMoveException::TOO_DEEP);
        (new TreeMover())->move($c, $child, 0, [], maxDepth: 2);
    }

    public function testSubtreeHeightCountsForTheDepth(): void
    {
        [$a, $b] = $this->nodes(2);
        $child = $this->node(10, 'child of b', 0, $b);
        self::assertSame($b, $child->getParent());

        // b brings one level of children along: below a it would reach level 3.
        $this->expectExceptionMessage(InvalidMoveException::TOO_DEEP);
        (new TreeMover())->move($b, $a, 0, [], maxDepth: 2, height: 2);
    }

    public function testMoveRequestAcceptsOnlyWellFormedMoves(): void
    {
        $move = MoveRequest::fromRequest($this->request('{"id": 5, "parent": null, "position": 2}'));
        self::assertSame([5, null, 2], [$move->id, $move->parent, $move->position]);
        self::assertSame(7, MoveRequest::fromRequest($this->request('{"id": 5, "parent": 7, "position": 0}'))->parent);

        foreach (['', 'nonsense', '[]', '{"id": "5", "parent": null, "position": 0}', '{"id": 5, "position": -1}', '{"id": 0, "position": 0}', '{"id": 5, "parent": "x", "position": 0}', '{"id": 5, "parent": 0, "position": 0}', '{"id": 5}'] as $body) {
            try {
                MoveRequest::fromRequest($this->request($body));
                self::fail('Accepted: '.$body);
            } catch (BadRequestHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function request(string $body): Request
    {
        return Request::create('/move', 'POST', content: $body);
    }

    /**
     * @return list<TreeNode&object{name: string}>
     */
    private function nodes(int $count): array
    {
        $nodes = [];
        for ($i = 0; $i < $count; ++$i) {
            $nodes[] = $this->node($i + 1, \chr(97 + $i), $i);
        }

        return $nodes;
    }

    /**
     * @return TreeNode&object{name: string}
     */
    private function node(int $id, string $name, int $position, ?TreeNode $parent = null): TreeNode
    {
        return new class($id, $name, $position, $parent) implements TreeNode {
            public function __construct(private readonly int $id, public readonly string $name, private int $position, private ?TreeNode $parent)
            {
            }

            public function getId(): int
            {
                return $this->id;
            }

            public function getParent(): ?TreeNode
            {
                return $this->parent;
            }

            public function setParent(?TreeNode $parent): static
            {
                $this->parent = $parent;

                return $this;
            }

            public function getPosition(): int
            {
                return $this->position;
            }

            public function setPosition(int $position): static
            {
                $this->position = $position;

                return $this;
            }
        };
    }

    /**
     * @param iterable<TreeNode&object{name: string}> $nodes
     *
     * @return array<string, int> name => position, ordered by position
     */
    private function positions(iterable $nodes): array
    {
        $positions = [];
        foreach ($nodes as $node) {
            $positions[$node->name] = $node->getPosition();
        }
        asort($positions);

        return $positions;
    }
}
