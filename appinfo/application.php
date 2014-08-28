<?php
/**
 * ownCloud - registration
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pellaeon Lin <pellaeon@hs.ntnu.edu.tw>
 * @copyright Pellaeon Lin 2014
 */

namespace OCA\Registration\AppInfo;


use \OCP\AppFramework\App;

use \OCA\Registration\Controller\RegistrationController;


class Application extends App {


	public function __construct (array $urlParams=array()) {
		parent::__construct('registration', $urlParams);

		$container = $this->getContainer();

		/**
		 * Controllers
		 */
		$container->registerService('RegistrationController', function($c) {
			return new RegistrationController(
				$c->query('AppName'), 
				$c->query('Request')
			);
		});


		/**
		 * Core
		 */
		$container->registerService('UserId', function($c) {
			return \OCP\User::getUser();
		});		
		
	}


}