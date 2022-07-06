<?php

namespace OCA\Social\Repository;

use OC\DB\ORM\EntityRepositoryAdapter;
use OCA\Social\Entity\Instance;

class InstanceRepository extends EntityRepositoryAdapter {
	public function getLocalInstance(): Instance {
		return $this->findOneBy(['local' => true]);
	}
}
