<?php
namespace OCA\Social\Tests;

use OCA\Social\Service\ConfigService;
use OCA\Social\Service\MiscService;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCP\IDBConnection;

// To test protected functions
class dummy extends StreamRequest {

	public function parseStreamSelectSql(array $data, string $as = Stream::TYPE): Stream {
		$stream = parent::parseStreamSelectSql($data);
		return $stream;
	}
}

class StreamRequestTest extends \PHPUnit\Framework\TestCase {

	public function testParseStreamSelectSql() {
		/**
		 * Dummy test to check if phpunit is working properly
		 */
                $data = [
                            'id' => 'https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662624101107825',
                            'type' => 'Note',
                            'to_array' => "https://www.w3.org/ns/activitystreams#Public",
                            'cc' => "https://mastodon.opportunis.me/users/MeTaL_PoU/followers",
			    'content' => "<p><span class=\"h-card\"><a href=\"https://social.artificial-owl.com/index.php/apps/social/@cult\" class=\"u-url mention\">@<span>cult</span></a></span>  <span class=\"h-card\"><a href=\"https://mastodon.xyz/@testing\" class=\"u-url mention\">@<span>testing</span></a></span> <br /><span class=\"h-card\"><a href=\"https://test.artificial-owl.com/apps/social/@cult\" class=\"u-url mention\">@<span>cult</span></a></span> </p><p>let's test !</p>",
                            'published' => '2019-08-22T20:55:25Z',
                            'published_time' => '2019-08-22 20:55:25',
                            'attributed_to' => 'https://mastodon.opportunis.me/users/MeTaL_PoU',
                            'in_reply_to' => 'https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662621906606409',
			    'source' => '{"@context":["https://www.w3.org/ns/activitystreams",{"ostatus":"http://ostatus.org#","atomUri":"ostatus:atomUri","inReplyToAtomUri":"ostatus:inReplyToAtomUri","conversation":"ostatus:conversation","sensitive":"as:sensitive","Hashtag":"as:Hashtag","toot":"http://joinmastodon.org/ns#","Emoji":"toot:Emoji","focalPoint":{"@container":"@list","@id":"toot:focalPoint"},"blurhash":"toot:blurhash"}],"id":"https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662624101107825","type":"Note","summary":null,"inReplyTo":"https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662621906606409","published":"2019-08-22T20:55:25Z","url":"https://mastodon.opportunis.me/@MeTaL_PoU/102662624101107825","attributedTo":"https://mastodon.opportunis.me/users/MeTaL_PoU","to":["https://www.w3.org/ns/activitystreams#Public"],"cc":["https://mastodon.opportunis.me/users/MeTaL_PoU/followers"],"sensitive":false,"atomUri":"https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662624101107825","inReplyToAtomUri":"https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662621906606409","conversation":"tag:mastodon.opportunis.me,2019-08-22:objectId=4205346:objectType=Conversation","content":"Some test content","attachment":[],"tag":[{"type":"Mention","href":"https://social.artificial-owl.com/index.php/apps/social/@cult","name":"@cult@social.artificial-owl.com"},{"type":"Mention","href":"https://mastodon.xyz/users/testing","name":"@testing"},{"type":"Mention","href":"https://test.artificial-owl.com/apps/social/@cult","name":"@cult@test.artificial-owl.com"}],"replies":{"id":"https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662624101107825/replies","type":"Collection","first":{"type":"CollectionPage","next":"https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662624101107825/replies?min_id=102662626313083874&page=true","partOf":"https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662624101107825/replies","items":["https://mastodon.opportunis.me/users/MeTaL_PoU/statuses/102662626313083874"]}},"_host":"mastodon.opportunis.me"}',
                            'local' => 0,
                            'creation' => '2019-08-23 04:39:57',
                            'hidden_on_timeline' => 0,
			    'details' => '{"boost":1}',
			    'cacheactor_id' => 'https://mastodon.opportunis.me/users/MeTaL_PoU',
                            'cacheactor_type' => 'Person',
                            'cacheactor_account' => 'MeTaL_PoU@mastodon.opportunis.me',
                            'cacheactor_following' => 'https://mastodon.opportunis.me/users/MeTaL_PoU/following',
                            'cacheactor_followers' => 'https://mastodon.opportunis.me/users/MeTaL_PoU/followers',
                            'cacheactor_inbox' => 'https://mastodon.opportunis.me/users/MeTaL_PoU/inbox',
                            'cacheactor_shared_inbox' => 'https://mastodon.opportunis.me/inbox',
                            'cacheactor_outbox' => 'https://mastodon.opportunis.me/users/MeTaL_PoU/outbox',
                            'cacheactor_featured' => 'https://mastodon.opportunis.me/users/MeTaL_PoU/collections/featured',
                            'cacheactor_url' => 'https://mastodon.opportunis.me/@MeTaL_PoU',
                            'cacheactor_preferred_username' => 'MeTaL_PoU',
			                                'cacheactor_name' => '...',
                            'cacheactor_summary' => '<p>Bibliothécaire et formatrice à lunettes roses.<br />Présidente d&apos;Exodus Privacy &lt;3<br />Raconte sa vie<br />Apprends</p>',
                            'cacheactor_public_key' => '-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEArnOfhUJ39NZ32ETsk+2N
LncfudHhCVm/MRJ5CmNUvPZ7x7w2Jho5kWVaZawVQec3znklWVcVr5R5G9jUcyzP
O83yXmu03oi9w/pc5g5qh0/1i9N+vl+AIxKNyihD9vEQoFZJ74cgYc+dLFQ03adq
vloWLzsCmNajxzksAUMR+aJUr3+lilcUf8G5Kp2SaevdQmiZ2eZRqsVXkPp0O6Jf
H3aMzLq/KBodZEuPnY0sWEMAPF2pCJP78hB1BKuRsPP2BKWf7QFLDb33AQFcbGlg
7XrSez+3TbgyFZSNewkbEqzwr4U+cj1BVvqIj+mN17qrK8I1wgEIEiT69i8svaCe
WwIDAQAB
-----END PUBLIC KEY-----',
                            'cacheactor_source' => '{"@context":["https://www.w3.org/ns/activitystreams","https://w3id.org/security/v1",{"manuallyApprovesFollowers":"as:manuallyApprovesFollowers","toot":"http://joinmastodon.org/ns#","featured":{"@id":"toot:featured","@type":"@id"},"alsoKnownAs":{"@id":"as:alsoKnownAs","@type":"@id"},"movedTo":{"@id":"as:movedTo","@type":"@id"},"schema":"http://schema.org#","PropertyValue":"schema:PropertyValue","value":"schema:value","Hashtag":"as:Hashtag","Emoji":"toot:Emoji","IdentityProof":"toot:IdentityProof","focalPoint":{"@container":"@list","@id":"toot:focalPoint"}}],"id":"https://mastodon.opportunis.me/users/MeTaL_PoU","type":"Person","following":"https://mastodon.opportunis.me/users/MeTaL_PoU/following","followers":"https://mastodon.opportunis.me/users/MeTaL_PoU/followers","inbox":"https://mastodon.opportunis.me/users/MeTaL_PoU/inbox","outbox":"https://mastodon.opportunis.me/users/MeTaL_PoU/outbox","featured":"https://mastodon.opportunis.me/users/MeTaL_PoU/collections/featured","preferredUsername":"MeTaL_PoU","name":"...","summary":"<p>Biblioth\u00e9caire et formatrice \u00e0 lunettes roses.<br />Pr\u00e9sidente d&apos;Exodus Privacy &lt;3<br />Raconte sa vie<br />Apprends</p>","url":"https://mastodon.opportunis.me/@MeTaL_PoU","manuallyApprovesFollowers":true,"publicKey":{"id":"https://mastodon.opportunis.me/users/MeTaL_PoU#main-key","owner":"https://mastodon.opportunis.me/users/MeTaL_PoU","publicKeyPem":"-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEArnOfhUJ39NZ32ETsk+2N\nLncfudHhCVm/MRJ5CmNUvPZ7x7w2Jho5kWVaZawVQec3znklWVcVr5R5G9jUcyzP\nO83yXmu03oi9w/pc5g5qh0/1i9N+vl+AIxKNyihD9vEQoFZJ74cgYc+dLFQ03adq\nvloWLzsCmNajxzksAUMR+aJUr3+lilcUf8G5Kp2SaevdQmiZ2eZRqsVXkPp0O6Jf\nH3aMzLq/KBodZEuPnY0sWEMAPF2pCJP78hB1BKuRsPP2BKWf7QFLDb33AQFcbGlg\n7XrSez+3TbgyFZSNewkbEqzwr4U+cj1BVvqIj+mN17qrK8I1wgEIEiT69i8svaCe\nWwIDAQAB\n-----END PUBLIC KEY-----\n"},"tag":[],"attachment":[{"type":"PropertyValue","name":"Music <3","value":"death(technique)/(post)black/Leprous"},{"type":"PropertyValue","name":"Avatar","value":"<span class=\"h-card\"><a href=\"https://mastodon.opportunis.me/@kaerhon\" class=\"u-url mention\">@<span>kaerhon</span></a></span>"}],"endpoints":{"sharedInbox":"https://mastodon.opportunis.me/inbox"},"icon":{"type":"Image","mediaType":"image/png","url":"https://mastodon.opportunis.me/system/accounts/avatars/000/035/552/original/f1360f47740442cf.png?1564131591"},"image":{"type":"Image","mediaType":"image/jpeg","url":"https://mastodon.opportunis.me/system/accounts/headers/000/035/552/original/048bf375cf78eab5.jpeg?1564131591"},"_host":"mastodon.opportunis.me"}',
                            'cacheactor_creation' => '2019-08-23 08:43:03',
                            'cacheactor_local' => 0,

		];

		$sr = new dummy(
				$this->createMock(IDBConnection::class),
				$this->createMock(ConfigService::class),
				$this->createMock(MiscService::class)
		);

		$stream = $sr->parseStreamSelectSql($data);

		print_r($stream);

		$this->assertTrue(true);
	}

}
