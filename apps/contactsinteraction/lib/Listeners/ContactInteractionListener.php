<?php

declare(strict_types=1);

/**
 * @copyright 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\ContactsInteraction\Listeners;

use OCA\ContactsInteraction\Db\CardSearchDao;
use OCA\ContactsInteraction\Db\RecentContact;
use OCA\ContactsInteraction\Db\RecentContactMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Contacts\Events\ContactInteractedWithEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ILogger;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\UUIDUtil;

class ContactInteractionListener implements IEventListener {

	/** @var RecentContactMapper */
	private $mapper;

	/** @var CardSearchDao */
	private $cardSearchDao;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var ILogger */
	private $logger;

	public function __construct(RecentContactMapper $mapper,
								CardSearchDao $cardSearchDao,
								ITimeFactory $timeFactory,
								ILogger $logger) {
		$this->mapper = $mapper;
		$this->cardSearchDao = $cardSearchDao;
		$this->timeFactory = $timeFactory;
		$this->logger = $logger;
	}

	public function handle(Event $event): void {
		if (!($event instanceof ContactInteractedWithEvent)) {
			return;
		}

		if ($event->getUid() === null && $event->getEmail() === null && $event->getFederatedCloudId() === null) {
			$this->logger->warning("Contact interaction event has no user identifier set");
			return;
		}

		$existing = $this->mapper->findMatch(
			$event->getActor(),
			$event->getUid(),
			$event->getEmail(),
			$event->getFederatedCloudId()
		);
		if (!empty($existing)) {
			$now = $this->timeFactory->getTime();
			foreach($existing as $c) {
				$c->setLastContact($now);
				$this->mapper->update($c);
			}

			return;
		}

		$contact = new RecentContact();
		$contact->setActorUid($event->getActor()->getUID());
		if ($event->getUid() !== null) {
			$contact->setUid($event->getUid());
		}
		if ($event->getEmail() !== null) {
			$contact->setEmail($event->getEmail());
		}
		if ($event->getFederatedCloudId() !== null) {
			$contact->setFederatedCloudId($event->getFederatedCloudId());
		}
		$contact->setLastContact($this->timeFactory->getTime());

		$copy = $this->cardSearchDao->findExisting(
			$event->getActor(),
			$event->getUid(),
			$event->getEmail(),
			$event->getFederatedCloudId()
		);
		if ($copy !== null) {
			$contact->setCard($copy);
		} else {
			$contact->setCard($this->generateCard($contact));
		}
		$this->mapper->insert($contact);
	}

	private function generateCard(RecentContact $contact): string {
		$props = [
			'URI' => UUIDUtil::getUUID(),
			'FN' => $contact->getEmail() ?? $contact->getUid() ?? $contact->getFederatedCloudId(),
		];

		if ($contact->getUid() !== null) {
			$props['X-NEXTCLOUD-UID'] = $contact->getUid();
		}
		if ($contact->getEmail() !== null) {
			$props['EMAIL'] = $contact->getEmail();
		}
		if ($contact->getFederatedCloudId() !== null) {
			$props['CLOUD'] = $contact->getFederatedCloudId();
		}

		return (new VCard($props))->serialize();
	}

}
