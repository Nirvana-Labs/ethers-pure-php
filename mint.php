<?php

namespace DEMO;

require 'ETH.php';

// NOTICE! Certain variables and params in this script must be updated

// Replace $MINTER_PRIVATE_KEY with your own wallet's private key
// NOTICE! Never share your private key with anyone as it is your password to sign transactions
$MINTER_PRIVATE_KEY = "d089e56f79010ffb4abc0844872dccde4f1c6fff04ad4335371199b23dddd327"; 

// Replace $MINTER_PUBLIC_KEY with your own wallet's public key (i.e. wallet address)
$MINTER_PUBLIC_KEY = "0x693f47ad695b5dcbbbd4b6e9c8c71f1292b56072"; 

// Address of the Rarible NFT smart contract we're minting from
$RARIBLE_NFT_ADDRESS = "0x6ede7F3c26975AAd32a475e1021D8F6F39c89d82";

// ABI is the NFT contract's binary interface 
$RARIBLE_NFT_ABI = json_decode('[{"inputs":[{"components":[{"internalType":"uint256","name": "tokenId","type": "uint256"},{"internalType": "string","name": "uri","type": "string"  },  {"components": [  {"internalType": "address payable","name": "account","type": "address"  },  {"internalType": "uint96",    "name": "value",   "type": "uint96"  }],"internalType": "struct LibPart.Part[]","name": "creators","type": "tuple[]"},   {"components": [  {    "internalType": "address payable",    "name": "account",    "type": "address"  },  {    "internalType": "uint96",    "name": "value",    "type": "uint96"  }],"internalType": "struct LibPart.Part[]","name": "royalties","type": "tuple[]"   },   {"internalType": "bytes[]","name": "signatures","type": "bytes[]"} ], "internalType": "struct LibERC721LazyMint.Mint721Data", "name": "data", "type": "tuple"},{ "internalType": "address", "name": "to", "type": "address"}],"name": "mintAndTransfer","outputs": [],"stateMutability": "nonpayable","type": "function"}]');

// Replace this $ETH_NODE_URL with your own node url
// NOTICE! This demo and the ETH_NODE_URL uses an Ethereum test network
$ETH_NODE_URL = "https://rinkeby.infura.io/v3/fde4edab65a94a2aa7dae9f9fe660090";

performMint();

function performMint() {
    global $RARIBLE_NFT_ADDRESS, $RARIBLE_NFT_ABI, $ETH_NODE_URL, $MINTER_PRIVATE_KEY, $MINTER_PUBLIC_KEY;

    $res = request('GET',
        sprintf('https://api-staging.rarible.com/protocol/v0.1/ethereum/nft/collections/%s/generate_token_id?minter=%s',
            $RARIBLE_NFT_ADDRESS,
            $MINTER_PUBLIC_KEY
        ));
    if (!$res->tokenId) {
        throw new \Error('Token Id is empty');
    }
    echo 'TOKEN ID: ' . $res->tokenId . "\n";

    $transactionCount = ethCall($ETH_NODE_URL, 'eth_getTransactionCount', [$MINTER_PUBLIC_KEY, 'pending'])->result;
    if (!$transactionCount) {
        throw new \Error('Transaction Count is empty');
    }

    // $params must be updated depending on network
    // i.e. gas price and gas will have different values for testnet vs mainnet. Sys admin script can calculate gas per network.
    $params = [
        'data' => encodeAbi(
            $RARIBLE_NFT_ABI,
            $res->tokenId,
            $MINTER_PUBLIC_KEY,
            '/ipfs/QmWLsBu6nS4ovaHbGAXprD1qEssJu4r5taQfB74sCG51tp'
        ),
        'to' => $RARIBLE_NFT_ADDRESS,
        'from' => $MINTER_PUBLIC_KEY,
        'chainId' => intval(ethCall($ETH_NODE_URL, 'net_version', [])->result),
        'gasPrice' => '0x' . base_convert(5000000000, 10, 16),
        'gas' => '0x' . base_convert(10000000, 10, 16),
        'nonce' => $transactionCount,
    ];
    $transaction = new Transaction($params);

    $transactionId = ethCall($ETH_NODE_URL, 'eth_sendRawTransaction', ['0x' . $transaction->sign($MINTER_PRIVATE_KEY)])->result;
    if (empty($transactionId)) {
        throw new \Error('Transaction id is empty');
    }

    echo 'Hash: ' . $transactionId . "\n";

    while (true) {
        sleep(3);
        $transactionReceipt = ethCall($ETH_NODE_URL, 'eth_getTransactionReceipt', [$transactionId])->result;
        if ($transactionReceipt) {
            echo 'Done: ' . json_encode($transactionReceipt) . "\n";
            break;
        }
    }
}

function encodeAbi($abi, $tokenId, $minter, $uri) {
    global $ETH_NODE_URL, $RARIBLE_NFT_ABI;
    $contract = new Contract($ETH_NODE_URL, $RARIBLE_NFT_ABI);
    return sprintf('0x22a775b6%s%s%s%s%s%s%s%s%s%s%s%s%s%s%s%s',
        IntegerFormatter::format(64),   // 40
        substr($contract->getEthabi()->encodeParameter('address', $minter), 2),
        substr($contract->getEthabi()->encodeParameter('uint256', $tokenId), 2),
        IntegerFormatter::format(160),  // a0
        IntegerFormatter::format(256),  // 100
        IntegerFormatter::format(352),  // 160
        IntegerFormatter::format(384),  // 180
        substr($contract->getEthabi()->encodeParameter('string', $uri), 2 + 64),
        IntegerFormatter::format(1),    // 1
        substr($contract->getEthabi()->encodeParameter('address', $minter), 2),
        IntegerFormatter::format(0),    // 0
        IntegerFormatter::format(0),    // 0
        IntegerFormatter::format(1),    // 1
        IntegerFormatter::format(32),   // 20
        IntegerFormatter::format(32),   // 20
        IntegerFormatter::format(0),    // 0
    );
}

function ethCall($endpoint, $method, $params) {
    return request('POST', $endpoint, [
        'id' => 1,
        'jsonrpc' => '2.0',
        'params' => $params,
        'method' => $method,
        'skipCache' => true
    ]);
}

function request($method, $url, $data=[]) {
    $ch = curl_init();
    try {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if (strtolower($method) === 'post') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode < 200 || $httpCode > 300) {
            $errorMsg = curl_error($ch);
            if (empty($errorMsg)) {
                $errorMsg = $result;
            }
            curl_close($ch);
            throw new \Error(sprintf('Curl Error: %s', $errorMsg));
        }
        $res = json_decode($result);
        curl_close($ch);
        if (!empty($res->error)) {
            throw new \Error($result);
        }

        return json_decode($result);
    } catch (\Exception $e) {
        echo 'request error: ' . $e->getMessage() . "\n";
        curl_close($ch);
        throw $e;
    }
}

