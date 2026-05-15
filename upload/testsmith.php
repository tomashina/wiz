<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


	  

	   $password = 'I)CRkT3JsdXg';

	$link = mysqli_connect("localhost", "wiz_2020", $password, "wiz_2020");

	if (!$link) {
	    echo "Error: Unable to connect to MySQL." . PHP_EOL;
	    echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
	    echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
	    exit;
	}

	mysqli_query($link,"TRUNCATE stockcake");

	$username = 'wizstore';
	$password = 'terminator001';
	$url = 'https://www.smit-electronic.hr/b2b/stanja.php?format=json';

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");

	$result = curl_exec($ch);$status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	echo $status_code;

	if($status_code == 200) {
	$json = json_decode($result, true);

		foreach ($json['artikl'] as $product) {

				echo $sifra = $product['sifra'];
			    echo $id = $product['stanjeSkladista']['id'];
			    echo $cijena = $product['stanjeSkladista']['opis'];

			    echo'<br>';

					// nešto napraviti s dobivenim podacima ...


			    if($id>0){

					$pmodel = $product['sifra'];
			         

			        $query = "INSERT INTO stockcake (model, quantity) VALUES ('$pmodel', '101')";

					$result = mysqli_query($link, $query);


				}

				else{


					$pmodel = $product['sifra'];
					

					$query = "INSERT INTO stockcake (model, quantity) VALUES ('$pmodel', '0')";

					$result = mysqli_query($link, $query);


				}








		}

	}

		//update product table
	$query = "
	UPDATE stockcake st, oc_product p
	SET p.quantity = st.quantity
	WHERE p.model = st.model " 
	;
	$result = mysqli_query($link, $query) or die ("Error in query: $query. ".mysqli_error($link));
	
	

echo 'done';
	

		//close connection
mysqli_close($link);