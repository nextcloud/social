<?php

namespace OCA\Social\Service;

class TrustedDomainChecker {
	public function check(string $domain): bool {
		// TODO extends to optionally support federation trusted domain list
		// and social domain block list
		return true;
	}
}
