<?php

namespace App\Tests\Functional;

use App\Entity\Post;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Enum\UserRole;
use App\Tests\AppWebTestCase;
use App\Workflow\ReviewWorkflow;

final class ReviewWorkflowTest extends AppWebTestCase
{
    public function testAuthorsSubmitAndEditorsApproveOrSendBack(): void
    {
        $this->requireReview();
        $author = $this->createUser(UserRole::Author);
        $editor = $this->createUser(UserRole::Editor);
        $post = $this->createPost('Harvest report', $author, status: ContentStatus::Draft, publishedAt: null);
        $url = '/admin/posts/'.$post->getId().'/edit';

        // The author can hand the draft in, but not publish it.
        $this->client->loginUser($author);
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectButton('Submit for review'));
        self::assertCount(0, $crawler->selectButton('Publish'));
        self::assertCount(0, $crawler->filter('input[name="post[status]"]'), 'Authors do not choose the status themselves.');

        // Not with a crafted request either.
        $form = $crawler->selectButton('Save draft')->form();
        $this->client->request('POST', $url, $form->getPhpValues() + ['publish' => '1']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url, array_replace_recursive($form->getPhpValues(), ['post' => ['status' => 'published']]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(ContentStatus::Draft, $this->post($post)->getStatus());

        $this->client->request('GET', $url);
        $this->client->submitForm('Submit for review');
        self::assertResponseRedirects($url);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'submitted');
        self::assertSelectorTextContains('.review-banner', 'waiting for review by an editor');
        self::assertSame(ContentStatus::Review, $this->post($post)->getStatus());
        self::assertCount(0, $crawler->selectButton('Approve and publish'));

        // Taking it back and handing it in again.
        $this->client->submitForm('Take back');
        self::assertSame(ContentStatus::Draft, $this->post($post)->getStatus());
        $this->client->request('GET', $url);
        $this->client->submitForm('Submit for review');
        self::assertSame(ContentStatus::Review, $this->post($post)->getStatus());

        // Visitors do not see it yet.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/blog/harvest-report');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/search?q=harvest');
        self::assertSelectorNotExists('.search-results');

        // The editor is told that something is waiting ...
        $this->client->loginUser($editor);
        $this->client->request('GET', '/admin/posts');
        self::assertSelectorTextContains('.flash--info', 'One post is waiting for review.');
        self::assertSelectorTextContains('.badge--review', 'In review');

        // ... and sends it back with a note.
        $crawler = $this->client->request('GET', $url);
        self::assertSelectorTextContains('.review-box', 'waiting for your review');
        self::assertCount(3, $crawler->filter('input[name="post[status]"]'));
        $this->client->submitForm('Send back', ['review_note' => "Please add the numbers of last year.\nThanks!"]);
        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'sent back');
        $stored = $this->post($post);
        self::assertSame(ContentStatus::Draft, $stored->getStatus());
        self::assertSame("Please add the numbers of last year.\nThanks!", $stored->getReviewNote());
        self::assertSame('Editor User', $stored->getReviewNoteBy());

        // The author reads the note, improves the post and hands it in again.
        $this->client->loginUser($author);
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('.review-box', 'Editor User sent this post back:');
        self::assertSelectorTextContains('.review-box__note', 'Please add the numbers of last year.');
        $this->client->submitForm('Submit for review', ['post[title]' => 'Harvest report 2026']);
        self::assertNull($this->post($post)->getReviewNote(), 'The note has done its job.');

        // This time the editor publishes it.
        $this->client->loginUser($editor);
        $this->client->request('GET', $url);
        $this->client->submitForm('Approve and publish');
        self::assertResponseRedirects($url);
        $stored = $this->post($post);
        self::assertSame(ContentStatus::Published, $stored->getStatus());
        self::assertNotNull($stored->getPublishedAt());
        self::assertSame($author->getId(), $stored->getAuthor()?->getId());

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/blog/harvest-report');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Harvest report 2026');

        // What is public is out of the author's hands.
        $this->client->loginUser($author);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/admin/posts');
        $token = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertCount(0, $crawler->filter('form[action$="/delete"]'));
        $this->client->request('POST', '/admin/posts/'.$post->getId().'/delete', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/posts/'.$post->getId().'/autosave', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: '{}');
        self::assertResponseStatusCodeSame(403);

        // An editor can take it offline again, which gives it back to the author.
        $this->client->loginUser($editor);
        $this->client->request('GET', $url);
        $this->client->submitForm('Save changes', ['post[status]' => 'draft']);
        self::assertSame(ContentStatus::Draft, $this->post($post)->getStatus());
        $this->client->loginUser($author);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testWithoutRequiredReviewAuthorsPublishAsBefore(): void
    {
        $author = $this->createUser(UserRole::Author);
        $post = $this->createPost('My notes', $author, status: ContentStatus::Draft, publishedAt: null);
        $url = '/admin/posts/'.$post->getId().'/edit';

        $this->client->loginUser($author);
        $crawler = $this->client->request('GET', $url);
        self::assertCount(1, $crawler->selectButton('Publish'));
        self::assertCount(0, $crawler->selectButton('Submit for review'));
        self::assertSame(['draft', 'published'], $crawler->filter('input[name="post[status]"]')->each(static fn ($node): string => (string) $node->attr('value')), '"In review" is not offered where reviews are not used.');

        $this->client->submitForm('Publish');
        self::assertSame(ContentStatus::Published, $this->post($post)->getStatus());

        // Authors keep editing and unpublishing their own posts.
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Save changes', ['post[status]' => 'draft']);
        self::assertSame(ContentStatus::Draft, $this->post($post)->getStatus());
    }

    public function testEditorsAndPagesAreNotSlowedDown(): void
    {
        $this->requireReview();
        $editor = $this->loginAs(UserRole::Editor);
        $post = $this->createPost('Editorial', $editor, status: ContentStatus::Draft, publishedAt: null);
        $page = $this->createPage('Imprint', ContentStatus::Draft);

        $crawler = $this->client->request('GET', '/admin/posts/'.$post->getId().'/edit');
        self::assertCount(1, $crawler->selectButton('Publish'));
        self::assertCount(0, $crawler->selectButton('Submit for review'));
        $this->client->submitForm('Publish');
        self::assertSame(ContentStatus::Published, $this->post($post)->getStatus());

        $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        $this->client->submitForm('Publish');
        $this->client->request('GET', '/imprint');
        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', '/admin/pages/'.$page->getId().'/edit');
        self::assertSame(['draft', 'published'], $crawler->filter('input[name="page[status]"]')->each(static fn ($node): string => (string) $node->attr('value')));
        $this->client->submitForm('Save changes', ['page[status]' => 'draft']);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/imprint');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminSwitchesTheReviewOnInTheSettings(): void
    {
        $this->loginAs(UserRole::Admin);
        $workflow = static fn (): ReviewWorkflow => static::getContainer()->get(ReviewWorkflow::class);
        self::assertFalse($workflow()->isReviewRequired());

        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[reviewRequired]' => true]);
        self::assertResponseRedirects('/admin/settings');
        self::assertTrue($workflow()->isReviewRequired());

        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['settings[reviewRequired]' => false]);
        self::assertFalse($workflow()->isReviewRequired());
    }

    public function testWorkflowOnlyAllowsItsOwnSteps(): void
    {
        $workflow = static::getContainer()->get(ReviewWorkflow::class);
        $post = $this->createPost('Steps', $this->createUser(UserRole::Author), status: ContentStatus::Draft, publishedAt: null);

        // Without a logged-in user (console, imports) every defined step is open, but only defined ones.
        self::assertTrue($workflow->can($post, ReviewWorkflow::SUBMIT));
        self::assertTrue($workflow->can($post, ReviewWorkflow::PUBLISH));
        self::assertFalse($workflow->can($post, ReviewWorkflow::APPROVE));
        self::assertFalse($workflow->can($post, ReviewWorkflow::UNPUBLISH));

        $workflow->apply($post, ReviewWorkflow::SUBMIT);
        self::assertSame(ContentStatus::Review, $post->getStatus());
        self::assertFalse($workflow->can($post, ReviewWorkflow::PUBLISH));
        self::assertSame([ContentStatus::Draft, ContentStatus::Review, ContentStatus::Published], $workflow->reachableStatuses($post));

        $workflow->apply($post, ReviewWorkflow::REJECT, 'Too short');
        self::assertSame(ContentStatus::Draft, $post->getStatus());
        self::assertSame('Too short', $post->getReviewNote());
    }

    private function requireReview(): void
    {
        static::getContainer()->get(ReviewWorkflow::class)->requireReview(true);
    }

    private function post(Post $post): Post
    {
        $this->em()->clear();

        return $this->entity(Post::class, $post->getId());
    }
}
