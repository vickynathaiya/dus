<?php

namespace Vickynathaiya\Dus\Services;

use Illuminate\Console\Command;

use ArkEcosystem\Crypto\Configuration\Network;
use ArkEcosystem\Crypto\Identities\Address;
use Vickynathaiya\Dus\Services\Networks\MainnetExt;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use ArkEcosystem\Crypto\Transactions\Builder\TransferBuilder;
use ArkEcosystem\Crypto\Transactions\Builder\MultiPaymentBuilder;
use Vickynathaiya\Dus\Services\Delegate;
use Vickynathaiya\Dus\Services\Server;

const base_url_infi = "https://api.infinitysolutions.io";
const base_url_edge = "https://api.edge.infinitysolutions.io";
const api_voters_infi_url = "/api/delegates/024844fa4b301ae6f9c514c963c18540630f1755dcca02ea9e91bae4b11d3dd1f1/voters";
const api_voters_edge_url = "/api/delegates/024844fa4b301ae6f9c514c963c18540630f1755dcca02ea9e91bae4b11d3dd1f1/voters";
const success = 1;

// const minVoterBalance = 100000;
		

class Voters
{
	public $eligibleVoters;
	public $totalVoters;
	public $nbEligibleVoters;

	public function initEligibleVoters(Delegate $delegate,$minVoterBalance) 
	{
		$this->eligibleVoters = [];
		$this->totalVoters = 0;
		$lockedBalance = 0;
		$retreive = success;
		$pageNum = 1;
		$debug = 1;
		$delegateAddress = $delegate->address;
		$delegateNetwork = $delegate->network;
		$delegatePublicKey = $delegate->publicKey;
		$api_voters_urlprefix = base_url_edge . "/api/delegates/" . $delegatePublicKey;

		// get fees from api
		if ($delegateNetwork == "infi") {
			$api_voters_urlprefix = base_url_infi . "/api/delegates/" . $delegatePublicKey;
		}

		$client = new Client();
		do {
			// build api voters url
			$urlVotersSuffix = "/voters?transform=true&page=$pageNum&limit=100";
			$api_voters_url = $api_voters_urlprefix . $urlVotersSuffix;
			if ($debug) {echo "\n Voters (initEliegbleVoters) : api_voters_url : $api_voters_url \n";}

			//retreive voters data
			$res = $client->get($api_voters_url);
			if (!($data = $res->getBody()->getContents())) 
			{
				//failed to retreive data
				echo "\n Voters (initEliegbleVoters) : retreiving voters data failed !! \n";
				$retreive = failed;
				break;
			} else {
				//get page meta data
				$data = json_decode($data);
				if ($pageNum == 1) {
					$this->totalVoters = $data->meta->totalCount;
					$pageCount = $data->meta->pageCount;
				}
			}

			$votersCount = $data->meta->count;
			if ($votersCount > 0) {
				$list_voters = $data->data;
				foreach ($list_voters as $voter) {
					$lockedBalance=0;
					$voter_balance = (int)$voter->balance;
					if (isset($voter->lockedBalance)) {
						$lockedBalance = $voter->lockedBalance;
					} 

					$voter_total_balance = $voter_balance + $lockedBalance;

					if (($delegateAddress != $voter->address) && ($voter_total_balance >= $minVoterBalance)) 
					{
						$this->eligibleVoters[] = array(
						'address' => $voter->address,
						'balance' => $voter->balance,
						'lockedBalance' => $lockedBalance,
						'portion' => 0,
						'amount' => 0,
						);
					}
					
				}
			}
			$pageNum++;
		} while ($votersCount == 100);
		$this->nbEligibleVoters = count($this->eligibleVoters);	
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
			$totalVotersBalance = $totalVotersBalance + $voter['balance'] + $voter['lockedBalance'];
		}

		//perform portion for each voter
		foreach ($this->eligibleVoters as $i => $voter) {
			$totalVoterBalance = $voter['balance'] + $voter['lockedBalance'];
			$portion = ($totalVoterBalance * 100) / $totalVotersBalance;
			echo "\n portion  : $portion \n";
			$this->eligibleVoters[$i]['portion'] = $portion;
		}
		return $this;
	}
}
