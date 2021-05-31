<?php

namespace Systruss\SchedTransactions\Services;

use Illuminate\Console\Command;

use ArkEcosystem\Crypto\Configuration\Network;
use ArkEcosystem\Crypto\Identities\Address;
use Systruss\SchedTransactions\Services\Networks\MainnetExt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use ArkEcosystem\Crypto\Transactions\Builder\TransferBuilder;
use ArkEcosystem\Crypto\Transactions\Builder\MultiPaymentBuilder;
use Systruss\SchedTransactions\Models\Delegate;
use Systruss\SchedTransactions\Services\Server;

const base_url_infi = "https://api.infinitysolutions.io";
const base_url_edge = "https://api.edge.infinitysolutions.io";
const api_voters_infi_url = "/api/delegates/024844fa4b301ae6f9c514c963c18540630f1755dcca02ea9e91bae4b11d3dd1f1/voters";
const api_voters_edge_url = "/api/delegates/024844fa4b301ae6f9c514c963c18540630f1755dcca02ea9e91bae4b11d3dd1f1/voters";

// const minVoterBalance = 100000;
		

class Voters 
{
	public $eligibleVoters;
	public $totalVoters;

	public function initEligibleVoters(Delegate $delegate,$minVoterBalance) 
	{
		
		$eligibleVoters = [];
		$this->totalVoters = 0;
		$delegateAddress = $delegate->address;
		$delegateNetwork = $delegate->netowrk;
		$delegatePublicKey = $delegate->publicKey;
		$api_voters_url = base_url_edge . "/api/delegates/" . $delegatePublicKey . "/voters";

		// get fees from api
		if ($delegateNetwork == "infi") {
			$api_voters_url = base_url_infi . "/api/delegates/" . $delegatePublicKey . "/voters";
		}

		$client = new Client();
		$res = $client->get($api_voters_url);
		if ($data = $res->getBody()->getContents()) 
		{
			$data = json_decode($data);
			$this->totalVoters = $data->meta->totalCount;
			if ($this->totalVoters > 0) {
				$list_voters = $data->data;
				foreach ($list_voters as $voter) {
					$voter_balance = (int)$voter->balance;
				
					if (($delegateAddress != $voter->address) && ($voter_balance >= $minVoterBalance)) 
					{
						$this->eligibleVoters[] = array(
						'address' => $voter->address,
						'balance' => $voter->balance,
						'portion' => 0,
						'amount' => 0,
						);
					}				
				}
			}
		}
		return $this;
	}
	
	public function calculatePortion($delegateAmount) 
	{
		//with eligibleVoters 
		//proportion (voter balance x 100) % sum of voters balance.
		
		$this->portionByVoter = [];
		//perform sum of eligible voters balance
		$totalVotersBalance = 0;
	
		foreach ($this->eligibleVoters as $voter) {
			$totalVotersBalance = $totalVotersBalance + $voter['balance'];
		}

		//perform portion for each voter
		foreach ($this->eligibleVoters as $i => $voter) {
			$portion = ($voter['balance'] * 100) / $totalVotersBalance;
			$this->eligibleVoters[$i]['portion'] = $portion;
		}
		return $this;
	}
}