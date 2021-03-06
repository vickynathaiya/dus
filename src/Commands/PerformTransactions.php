<?php

namespace Vickynathaiya\Dus\Commands;

use Illuminate\Console\Command;

use ArkEcosystem\Crypto\Configuration\Network;
use ArkEcosystem\Crypto\Identities\Address;
use Illuminate\Database\QueryException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use ArkEcosystem\Crypto\Transactions\Builder\TransferBuilder;
use ArkEcosystem\Crypto\Transactions\Builder\MultiPaymentBuilder;
use Vickynathaiya\Dus\Services\Networks\MainnetExt;
use Vickynathaiya\Dus\Services\Voters;
use Vickynathaiya\Dus\Services\Delegate;
use Vickynathaiya\Dus\Services\Beneficary;
use Vickynathaiya\Dus\Services\Transactions;
use Vickynathaiya\Dus\Services\SchedTransaction;
use Vickynathaiya\Dus\Models\CryptoLog;

const SCHED_NB_HOURS = 6;


class PerformTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crypto:perform_transactions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'perform transactions every 24 hours';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $disabled = 0;
        
		$this->info("---------------------------------------------");
        echo date('d-m-y h:i:s'); 
        $this->info(" : starting a new transaction");


		//Initialise Delegate
		$delegate = new Delegate();
        $success = $delegate->initFromDB();
        if (!$success) 
        {
            $this->info(" PerformTransactions(cmd) : Error initialising Delegate from DB ");
            return false;
        }

        // check if scheduler is active
        if (!$delegate->sched_active) {
            echo "\n PerformTransactions(cmd) : Scheduler is not active, activate scheduler using : php artisan crypto:admin enable_sched \n";
            return;
        }

        // scheduler active , check counter last transactions
        $sched_freq = $delegate->sched_freq;
        $latest_transactions = CryptoLog::orderBy('id','DESC')->first();
        if ($latest_transactions) {
            if (($latest_transactions->succeed) && ($latest_transactions->hourCount < $sched_freq)) {
                $next_transactions =  $sched_freq - $latest_transactions->hourCount;
                $latest_transactions->hourCount = $latest_transactions->hourCount + 1;
                $latest_transactions->save();
                $this->info("PerformTransactions(cmd) : Next Transactions in $next_transactions hours");
                return;
            }
        }
 
        //check delegate validity
        $valid = $delegate->checkDelegateValidity();

		// Check Delegate  Eligibility
        $this->info(" PerformTransactions(cmd) :  ----------- checking delegate elegibility");
        // $transactions = new Transactions();
        $success = $delegate->checkDelegateEligibility();
		if (!$success) {
			$this->info("PerformTransactions(cmd) : (error) delegate is not yet eligble trying after an hour");
			return false;
		}
        $this->info("PerformTransactions(cmd) : (success) delegate is eligible");

        // get beneficary and amount = (delegate balance - totalFee) * 20%
        $this->info(" PerformTransactions(cmd) : ---------------- get benificiary info");
        $beneficary = new Beneficary();
        $success = $beneficary->initBeneficary($delegate);
        if (!$success) {
            $this->info("PerformTransactions(cmd) : an issue happened with the beneficary");
			return false; 
        }
        $requiredMinimumBalance = $beneficary->requiredMinimumBalance;

        //init voters
        $this->info(" PerformTransactions(cmd) : ---------- initialising voters");
		$voters = new voters();
        $voters = $voters->initEligibleVoters($delegate,$requiredMinimumBalance);
		if (!($voters->totalVoters > 0)) {
			echo "\n there is no Eligible voters \n";
			return false;
		}
        $this->info("PerformTransactions(cmd) : voters initialized successfully \n ");
        $this->info("PerformTransactions(cmd) : number of Elegible voters " . $voters->nbEligibleVoters);
    
        $transactions = new Transactions();
        $transactions = $transactions->buildTransactions($voters,$delegate,$beneficary);
        if (!$transactions->buildSucceed) {
            $this->info("PerformTransactions(cmd) : (error) " . $transactions->errMesg);
            return false;
        }
        //log transaction
        $cryptoLog = new CryptoLog();
        $cryptoLog->rate = $beneficary->rate;
        $cryptoLog->beneficary_address = $beneficary->address;
        $cryptoLog->delegate_address = $delegate->address;
        $cryptoLog->delegate_balance = $delegate->balance;
        $cryptoLog->fee = $transactions->fee;
        $cryptoLog->amount = $transactions->amountToBeDistributed;
        $cryptoLog->totalVoters = $voters->totalVoters;
        $cryptoLog->totalEligibleVoters = $voters->nbEligibleVoters;
        $cryptoLog->transactions = count($transactions->transactions);
        $cryptoLog->hourCount = 0;
        $cryptoLog->succeed = false;

        $this->info("PerformTransactions(cmd) : transaction initialized successfully");
        $this->info("PerformTransactions(cmd) : ready to run the folowing transactions ");
        echo json_encode($transactions->transactions, JSON_PRETTY_PRINT);
        echo "\n";

        //for simulation transactions status
        $succeed = 1;
        if ($succeed) {
            $cryptoLog->succeed = true;
            $cryptoLog->save();
        }

        if (!$disabled) {
            //perform transactions
            echo "\n PerformTransactions(cmd) : performing the transactions \n";
            $success = $transactions->sendTransactions();
            if (!$success) {
                echo "\n PerformTransactions(cmd) : error while sending transactions \n";
                return 0;
            }
            $this->info("PerformTransactions(cmd) : transactions performed successefully");
            echo json_encode($transactions->transactions );
        } 
    }
}
