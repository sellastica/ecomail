<?php
namespace Sellastica\Ecomail;

use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Sellastica\Ecomail\Exception\BadRequestException;
use Sellastica\Ecomail\Exception\InvalidCredentialsException;
use Sellastica\Ecomail\Exception\InvalidResponseException;
use Sellastica\Ecomail\Exception\NotFoundException;
use Sellastica\Ecomail\Exception\UnknownResponseException;
use Sellastica\EmailProvider\IEmailProvider;

class Ecomail implements IEmailProvider
{
	const ACCEPTS_MARKETING = 1,
		DOES_NOT_ACCEPT_MARKETING = 2;

	const MAX_BATCH_ITEMS = 3000; //3000 is maximal limit from ecomail

	private const API_URL = 'http://api2.ecomailapp.cz';

	/** @var string */
	private $apiKey;
	/** @var mixed */
	private $lastResponseRaw;
	/** @var mixed */
	private $lastResponse;
	/** @var int|null */
	private $lastStatusCode;
	/** @var string|null */
	private $lastCalledUrl;
	/** @var array|null */
	private $lastCalledBody;
	/** @var string|null */
	private $lastCalledBodyJson;
	/** @var array|null */
	private $lastHeaders;


	/**
	 * @param string $apiKey
	 */
	public function __construct(string $apiKey)
	{
		$this->apiKey = $apiKey;
	}

	/**
	 * @return mixed
	 */
	public function getLastResponseRaw()
	{
		return $this->lastResponseRaw;
	}

	/**
	 * @return mixed
	 */
	public function getLastResponse()
	{
		return $this->lastResponse;
	}

	/**
	 * @return bool
	 */
	public function isLastResponseOk(): bool
	{
		return $this->lastStatusCode < 300;
	}

	/**
	 * @return int|null
	 */
	public function getLastStatusCode(): ?int
	{
		return $this->lastStatusCode;
	}

	/**
	 * @return array|null
	 */
	public function getLastCalledBody(): ?array
	{
		return $this->lastCalledBody;
	}

	/**
	 * @return null|string
	 */
	public function getLastCalledBodyJson(): ?string
	{
		return $this->lastCalledBodyJson;
	}

	/**
	 * @return array|null
	 */
	public function getLastHeaders(): ?array
	{
		return $this->lastHeaders;
	}

	// === Ping ===

	/**
	 * @return bool
	 */
	public function ping(): bool
	{
		try {
			$this->get('lists', ['per_page' => 1]);
			return true;
		} catch (InvalidCredentialsException $e) {
			return false;
		}
	}

	// === Lists ===

	/**
	 * Práce se seznamy kontaktů a s přihlášenými odběrateli
	 * @return array
	 */
	public function getLists(): array
	{
		return $this->get('lists');
	}


	/**
	 * Vložení nového seznamu kontaktů
	 * @param array $data Data
	 * @return array
	 */
	public function addList(array $data): array
	{
		return $this->post('lists', $data);
	}


	/**
	 * @param string $listId ID listu
	 * @return array
	 */
	public function showList($listId): array
	{
		$url = $this->joinString('lists/', $listId);
		return $this->get($url);
	}


	/**
	 * @param string $listId ID listu
	 * @param array $data Data
	 * @return array
	 */
	public function updateList($listId, array $data): array
	{
		$url = $this->joinString('lists/', $listId);
		return $this->put($url, $data);
	}


	/**
	 * @param string $listId ID listu
	 * @param array|null $params
	 * @return array
	 */
	public function getSubscribers($listId, array $params = null): array
	{
		$url = $this->joinString('lists/', $listId, '/subscribers');
		return $this->get($url, $params);
	}


	/**
	 * @param string $listId ID listu
	 * @param string $email Email
	 * @return array
	 */
	public function getSubscriber($listId, $email): array
	{
		$url = $this->joinString('lists/', $listId, '/subscriber/', $email);
		return $this->get($url);
	}


	/**
	 * @param string $listId ID listu
	 * @param array $data Data
	 * @return array
	 */
	public function addSubscriber($listId, array $data): array
	{
		$url = $this->joinString('lists/', $listId, '/subscribe');
		return $this->post($url, $data);
	}


	/**
	 * @param string $listId ID listu
	 * @param string $email
	 * @return array
	 */
	public function removeSubscriber($listId, string $email): array
	{
		$url = $this->joinString('lists/', $listId, '/unsubscribe');
		return $this->delete($url, ['email' => $email]);
	}


	/**
	 * @param string $listId ID listu
	 * @param array $data Data
	 * @return array
	 */
	public function updateSubscriber($listId, array $data): array
	{
		$url = $this->joinString('lists/', $listId, '/update-subscriber');
		return $this->put($url, $data);
	}


	/**
	 * @param string $listId ID listu
	 * @param array $data Data
	 * @return array
	 */
	public function addSubscriberBulk($listId, array $data): array
	{
		$url = $this->joinString('lists/', $listId, '/subscribe-bulk');
		return $this->post($url, $data);
	}


	// === Campaigns ===


	/**
	 * @param string $filters Filtr
	 * @return array
	 */
	public function listCampaigns($filters = null): array
	{
		$url = $this->joinString('campaigns');
		if (!is_null($filters)) {
			$url = $this->joinString($url, '?filters=', $filters);
		}
		return $this->get($url);
	}


	/**
	 * @param array $data Data
	 * @return array
	 */
	public function addCampaign(array $data): array
	{
		$url = $this->joinString('campaigns');
		return $this->post($url, $data);
	}


	/**
	 * @param int $campaign_id ID kampaně
	 * @param array $data Data
	 * @return array
	 */
	public function updateCampaign($campaign_id, array $data): array
	{
		$url = $this->joinString('campaigns/', $campaign_id);
		return $this->put($url, $data);
	}


	/**
	 * Toto volání okamžitě zařadí danou kampaň do fronty k odeslání.
	 * Tuto akci již nelze vrátit zpět.
	 *
	 * @param int $campaign_id ID kampaně
	 * @return array
	 */
	public function sendCampaign($campaign_id): array
	{
		$url = $this->joinString('campaigns/', $campaign_id, '/send');
		return $this->get($url);
	}


	// === Reports ===


	// === Automation ===

	/**
	 * @return array
	 */
	public function listAutomations(): array
	{
		$url = $this->joinString('automation');
		return $this->get($url);
	}


	// === Templates ===

	/**
	 * @param array $data Data
	 * @return array
	 */
	public function createTemplate(array $data): array
	{
		$url = $this->joinString('template');
		return $this->post($url, $data);
	}


	// === Domains ===

	/**
	 * @return array
	 */
	public function listDomains(): array
	{
		$url = $this->joinString('domains');
		return $this->get($url);
	}


	/**
	 * @param array $data Data
	 * @return array
	 */
	public function createDomain(array $data): array
	{
		$url = $this->joinString('domains');
		return $this->post($url);
	}


	/**
	 * @param int $id ID domény
	 * @return array
	 */
	public function deleteDomain($id): array
	{
		$url = $this->joinString('domains/', $id);
		return $this->delete($url);
	}


	// ===  Transakční e-maily ===

	/**
	 * @param   array $data Data
	 * @return  array
	 */
	public function sendTransactionalEmail(array $data): array
	{
		$url = $this->joinString('transactional/send-message');
		return $this->post($url, $data);
	}


	/**
	 * @param   array $data Data
	 * @return  array
	 */
	public function sendTransactionalTemplate(array $data): array
	{
		$url = $this->joinString('transactional/send-template');
		return $this->post($url, $data);
	}


	// === Tracker ===


	/**
	 * @param   array $data Data
	 * @return  array
	 */
	public function createNewTransaction(array $data): array
	{
		$url = $this->joinString('tracker/transaction');
		return $this->post($url, $data);
	}


	/**
	 * Spojování textu
	 *
	 * @return string
	 */
	private function joinString(): string
	{
		$join = "";
		for ($i = 0; $i < func_num_args(); $i++) {
			$join .= func_get_arg($i);
		}
		return $join;
	}


	// === cURL ===


	/**
	 * Pomocná metoda pro GET
	 *
	 * @param   string $request Požadavek
	 * @param array|null $params
	 * @return array
	 */
	private function get($request, array $params = null): array
	{
		return $this->send($request, $params);
	}


	/**
	 * Pomocná metoda pro POST
	 *
	 * @param   string $request Požadavek
	 * @param   null|array $data Zaslaná data
	 * @return  array|null
	 */
	private function post($request, array $data): ?array
	{
		return $this->send($request, null, $data);
	}


	/**
	 * Pomocná metoda pro PUT
	 *
	 * @param   string $request Požadavek
	 * @param   null|array $data Zaslaná data
	 * @return  array
	 */
	private function put($request, array $data = []): array
	{
		return $this->send($request, null, $data, 'put');
	}


	/**
	 * Pomocná metoda pro DELETE
	 *
	 * @param   string $request Požadavek
	 * @param array|null $body
	 * @return  array
	 * @throws \Sellastica\Ecomail\Exception\BadRequestException
	 * @throws \Sellastica\Ecomail\Exception\InvalidCredentialsException
	 * @throws \Sellastica\Ecomail\Exception\InvalidResponseException
	 * @throws \Sellastica\Ecomail\Exception\NotFoundException
	 * @throws \Sellastica\Ecomail\Exception\UnknownResponseException
	 */
	private function delete($request, array $body = null): array
	{
		return $this->send($request, null, $body, 'delete');
	}

	/**
	 * @param   string $request
	 * @param   null|array $body
	 * @param   null|string $method
	 * @param array|null $params
	 * @return array|null Null if nothing is found (404 status code)
	 * @throws \Sellastica\Ecomail\Exception\BadRequestException
	 * @throws \Sellastica\Ecomail\Exception\InvalidCredentialsException
	 * @throws \Sellastica\Ecomail\Exception\InvalidResponseException
	 * @throws NotFoundException
	 * @throws UnknownResponseException
	 */
	private function send($request, array $params = null, $body = null, $method = null): ?array
	{
		$url = self::API_URL . '/' . $request;
		if ($params !== null) {
			$url .= '?' . http_build_query($params);
		}

		$this->lastCalledUrl = $url;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		if (!is_null($method)) {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		}

		$this->lastCalledBody = $body;
		if (is_array($body)) {
			$body = $this->lastCalledBodyJson = Json::encode($body);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		//headers
		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->lastHeaders = [
			'Content-Type: application/json',
			'Accept: application/json',
			'key: ' . $this->apiKey,
		]);

		$this->lastResponse = $this->lastResponseRaw = curl_exec($ch);
		$this->lastStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		curl_close($ch);

		if ($errno) {
			throw new InvalidResponseException(
				sprintf('cURL responsed with error code %s: %s', $errno, $error)
			);
		} else {
			switch ($this->lastStatusCode) {
				case 200:
				case 201:
				case 204:
					try {
						$this->lastResponse = Json::decode($this->lastResponse, Json::FORCE_ARRAY);
					} catch (JsonException $e) {
						throw new InvalidResponseException('Response is not in JSON format');
					}

					return $this->lastResponse;

					break;
				case 400:
					throw new BadRequestException($this->lastResponse, 400);
					break;
				case 401:
					//do not log
					throw new InvalidCredentialsException('Invalid credentials', 401);
					break;
				case 404:
					throw new NotFoundException(is_string($this->lastResponse) ? $this->lastResponse : '', 404);
					break;
				default:
					throw new UnknownResponseException('Uknown response code', $this->lastStatusCode);
					break;

			}
		}
	}
}
