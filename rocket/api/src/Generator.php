<?php
/**
 * HEADS UP!
 * This file will give you nightmares, but it works!
 * A major refactor is underway.
 *
 * 
 */

namespace Rocket\Api;


class Generator{

	protected $system;
	protected $specs;
	protected $routes;

	public function __construct($system)
	{
		$this->system = $system;

		$this->loadSpecs();
		$this->generateContexts();
		//$this->generateTraits();
		$this->generateResources();
		$this->generateRoutes();

		$sync = $this->syncDb();
		if ($sync['changes'] > 0){
			$this->sync($sync);
		}
	}

	public function loadSpecs()
	{
		//echo 'loading specs'.PHP_EOL;
		$specFile = $this->system->config['payload'] . $this->system->config['spec_file'];

		$path = pathinfo($specFile, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR;

		$specContent = file_get_contents($specFile);

		if (preg_match_all('/"include (.+)"/', $specContent, $matches, PREG_SET_ORDER)){
			foreach ($matches as $match){
				$specContent = str_replace($match[0], file_get_contents($path . $match[1]), $specContent);
			}
		}

		$this->specs = json_decode($specContent);
	}

	public function generateContexts()
	{
		//echo 'generating contexts'.PHP_EOL;
		ob_start();
		echo "return array(" . PHP_EOL;
		$contexts = array();
		$apiContexts = (array)$this->specs->contexts;
		uasort($apiContexts, function($a, $b){
			return (count($a) < count($b));
		});
		foreach ($apiContexts as $contextName => $context){
			$checks = array();
			foreach ($context as $check){
				if (strpos($check, '::')){
					$c = explode('::', $check);
					$checks[] = "array(\"$c[0]\", \"$c[1]\")";
				}else{
					$checks[] = "array(false, \"$check\")";
				}
				//$checks[] = "\"$check\"";
			}
			$contexts[] = "\t" . "\"$contextName\" => array(" . implode(',', $checks) . ")";
		}
		echo implode(',' . PHP_EOL, $contexts);
		echo PHP_EOL . ");";

		$src = ob_get_contents();
		ob_end_clean();

		file_put_contents($this->system->config['core_path'] . 'contexts.php', '<?php ' . PHP_EOL . $src);
	}

	/*public function generateTraits()
	{
		foreach ($this->specs->traits as $traitName => $trait){
			if (isset($trait->on_spec)){
				$hook = $trait->on_spec;
				if (strpos($hook, '::')){
					$c = explode('::', $hook);
					$hook = array($c[0], $c[1]);
				}else{
					$hook = array(false, $hook);
				}
				$traitSpecs = new \stdClass();
				$traitSpecs->properties = new \stdClass();
				\Rocket::call($hook, $traitSpecs);

				$trait->spec = $traitSpecs;
			}
		}
	}*/

	public function generateResources()
	{
		$this->routes = array();
		foreach ($this->specs->resources as $resourceName => $resource){
			//echo 'generating resources: '.$resourceName . PHP_EOL;

			if (isset($resource->traits)){
				foreach ($resource->traits as $traitName){
					if (method_exists($traitName, 'on_properties')){
						\Rocket::call(array($traitName, "on_properties"), $resource->properties);
					}
				}
			}

			ob_start();

			echo "/**" . PHP_EOL;
			echo " * This class has been autogenerated by RocketPHP" . PHP_EOL;
			echo " */" . PHP_EOL . PHP_EOL;
			echo "namespace Resources;" . PHP_EOL . PHP_EOL;
			echo "class $resourceName {" . PHP_EOL . PHP_EOL;

			echo "\t" . "protected \$db;" . PHP_EOL;
			echo "\t" . "protected \$fields = array(\"".implode('","', array_keys((array)$resource->properties))."\");" . PHP_EOL;
			echo  PHP_EOL;

			echo "\t" . "function __construct(\$db){" . PHP_EOL;
			echo "\t\t" . "\$this->db = \$db;" . PHP_EOL;
			echo "\t" . "}" . PHP_EOL . PHP_EOL;

			// TODO: extend base class and put these methods there
			echo "\t" . "protected function getDataForQuery(\$query, \$data){" . PHP_EOL;
			echo "\t\t" . "\$queryData = array();" . PHP_EOL;
			echo "\t\t" . "preg_match_all('/:([a-zA-Z0-9_]+)/im', \$query, \$matches, PREG_SET_ORDER);" . PHP_EOL;
			echo "\t\t" . "if (count(\$matches)){" . PHP_EOL;
			echo "\t\t\t" . "foreach (\$matches as \$match){" . PHP_EOL;
			echo "\t\t\t\t" . "if (isset(\$data[\$match[1]])){" . PHP_EOL;
			echo "\t\t\t\t\t" . "\$queryData[\$match[1]] = \$data[\$match[1]];" . PHP_EOL;
			echo "\t\t\t\t" . "}" . PHP_EOL;
			echo "\t\t\t" . "}" . PHP_EOL;
			echo "\t\t" . "}" . PHP_EOL;
			echo "\t\t" . "return \$queryData;" . PHP_EOL;
			echo "\t" . "}" . PHP_EOL . PHP_EOL;

			// Render all validation and reciever methods
			foreach ($resource->properties as $propertyName => $property){
				echo "\t" . "function receive_$propertyName(\$value, &\$errors) {" . PHP_EOL;
				echo "\t\t" . "\$errors = array_merge(\$errors, \$this->validate_$propertyName(\$value));" . PHP_EOL;
				if (isset($property->on_receive)){
					$on_receive = explode('.', $property->on_receive);
					// TODO: pass data and errors
					echo "\t\t" . "\Rocket::call(array(\"$on_receive[0]\", \"$on_receive[1]\"), \$value, \$errors);" . PHP_EOL;
				}
				echo "\t\t" . "return \$value;" . PHP_EOL;
				echo "\t}" . PHP_EOL . PHP_EOL;

				echo "\t" . "function validate_$propertyName(\$value) {" . PHP_EOL;
				echo "\t\t". "\$errors = array();" . PHP_EOL;
				switch ($property->type){
					case "string":
						echo "\t\t" . "if (!is_string(\$value)){ \$errors[] = \"$propertyName.incorrectType.string\"; }" . PHP_EOL;
						break;
					case "int":
						echo "\t\t" . "if (!is_int(\$value)){ \$errors[] = \"$propertyName.incorrectType.int\"; }" . PHP_EOL;
						break;
					case "float":
					case "double":
						echo "\t\t" . "if (!is_float(\$value)){ \$errors[] = \"$propertyName.incorrectType.float\"; }" . PHP_EOL;
						break;
					case "datetime":
						echo "\t\t" . "if (!is_date(\$value, 'Y-m-d H:i:s')){ \$errors[] = \"$propertyName.incorrectType.datetime\"; }" . PHP_EOL;
						break;
					case "date":
						echo "\t\t" . "if (!is_date(\$value, 'Y-m-d')){ \$errors[] = \"$propertyName.incorrectType.date\"; }" . PHP_EOL;
						break;
					case "time":
						// TODO: this can be improved, am/pm? no seconds?
						echo "\t\t" . "if (!is_date(\$value, 'H:i:s')){ \$errors[] = \"$propertyName.incorrectType.time\"; }" . PHP_EOL;
						break;
					case "email":
						echo "\t\t" . "if (!filter_var(\$value, FILTER_VALIDATE_EMAIL)){ \$errors[] = \"$propertyName.incorrectType.email\"; }" . PHP_EOL;
						break;
				}
				if (isset($property->max_length)){
					echo "\t\t" . "if (strlen(\$value) > $property->max_length){ \$errors[] = \"$propertyName.tooLong\"; }" . PHP_EOL;
				}
				if (isset($property->min_length)){
					echo "\t\t" . "if (strlen(\$value) < $property->min_length){ \$errors[] = \"$propertyName.tooShort\"; }" . PHP_EOL;
				}
				if (isset($property->matches)){
					echo "\t\t" . "if (!preg_match(\"$property->matches\", \$value)){ \$errors[] = \"$propertyName.patternMatch\"; }" . PHP_EOL;
				}
				if (isset($property->max)){
					if (is_object($property->type)){
					echo "\t\t" . "if (count(\$value) > $property->max){ \$errors[] = \"$propertyName.tooMany\"; }" . PHP_EOL;
					}else{
					echo "\t\t" . "if (\$value > $property->max){ \$errors[] = \"$propertyName.tooLarge\"; }" . PHP_EOL;
					}
				}
				if (isset($property->min)){
					if (is_object($property->type)){
					echo "\t\t" . "if (count(\$value) < $property->min){ \$errors[] = \"$propertyName.tooFew\"; }" . PHP_EOL;
					}else{
					echo "\t\t" . "if (\$value < $property->min){ \$errors[] = \"$propertyName.tooSmall\"; }" . PHP_EOL;
					}
				}

				echo "\t\t" . "return \$errors;" . PHP_EOL;
				echo "\t}" . PHP_EOL . PHP_EOL;

				if (is_object($property->type)){
					echo "\t" . "function $propertyName(\$id) {" . PHP_EOL;
					echo "\t\t" . "// TODO: return query here so that users can customize result eg. LIMIT, ORDER BY, WHERE x, etc" . PHP_EOL;
					$target = $this->getResource($property->type->resource);
					$targetProperty = $target->properties->{$property->type->on};

					if ($property->type->relation == 'has-one'){
						if ($targetProperty->type->relation == 'has-many'){
							if (isset($property->type->sql)){
								echo "\t\t" . "\$query = \"{$property->type->sql}\";" . PHP_EOL;
							}else{
								echo "\t\t" . "\$query = \"SELECT * FROM {$property->type->resource} WHERE id = :id\";" . PHP_EOL;
							}
							echo "\t\t" . "\$statement = \$this->db->prepare(\$query);" . PHP_EOL;
							echo "\t\t" . "\$statement->execute(array('id' => \$id));" . PHP_EOL;
							echo "\t\t" . "\$data = \$statement->fetch(\PDO::FETCH_ASSOC);" . PHP_EOL;
						}else{
							$resources = array($property->type->resource, $propertyName);
							sort($resources);
							if (isset($property->type->sql)){
								echo "\t\t" . "\$query = \"{$property->type->sql}\";" . PHP_EOL;
							}else{
								echo "\t\t" . "\$query = \"SELECT * FROM $resources[0] WHERE id = :id\";" . PHP_EOL;
							}
							echo "\t\t" . "\$statement = \$this->db->prepare(\$query);" . PHP_EOL;
							echo "\t\t" . "\$statement->execute(array('id' => \$id));" . PHP_EOL;
							echo "\t\t" . "\$data = \$statement->fetch(\PDO::FETCH_ASSOC);" . PHP_EOL;
						}
					}

					if ($property->type->relation == 'has-many'){
						if ($targetProperty->type->relation == 'has-many'){
							$resources = array($propertyName, $property->type->on);
							sort($resources);

							if (isset($property->type->sql)){
								echo "\t\t" . "\$query = \"{$property->type->sql}\";" . PHP_EOL;
							}else{
								echo "\t\t" . "\$query = \"SELECT {$property->type->resource}.* FROM {$property->type->resource} JOIN $resources[0]_$resources[1] ON {$property->type->resource}.id = $resources[0]_$resources[1].{$propertyName}_id WHERE $resources[0]_$resources[1].{$property->type->on}_id = :id\";" . PHP_EOL;
							}
							echo "\t\t" . "\$statement = \$this->db->prepare(\$query);" . PHP_EOL;
							echo "\t\t" . "\$statement->execute(array('id' => \$id));" . PHP_EOL;
							echo "\t\t" . "\$data = \$statement->fetchAll(\PDO::FETCH_ASSOC);" . PHP_EOL;
						}else{
							if (isset($property->type->sql)){
								echo "\t\t" . "\$query = \"{$property->type->sql}\";" . PHP_EOL;
							}else{
								echo "\t\t" . "\$query = \"SELECT * FROM {$property->type->resource} WHERE {$property->type->on}_id = :id\";" . PHP_EOL;
							}
							echo "\t\t" . "\$statement = \$this->db->prepare(\$query);" . PHP_EOL;
							echo "\t\t" . "\$statement->execute(array('id' => \$id));" . PHP_EOL;
							echo "\t\t" . "\$data = \$statement->fetchAll(\PDO::FETCH_ASSOC);" . PHP_EOL;
						}
					}
					echo "\t\t" . "" . PHP_EOL;
					echo "\t\t" . "// TODO: \$data = customHook(\$data);" . PHP_EOL;
					echo "\t\t" . "return \$data;" . PHP_EOL;
					echo "\t}" . PHP_EOL . PHP_EOL;
				}
			}

			// Render all endpoints
			foreach ($resource->endpoints as $route => $endpoint){
				preg_match_all('/\{([^\}]+)\}/', $route, $matches, PREG_SET_ORDER);
				//print_r($matches);
				$args = array('$data');
				$argNames = array();
				$routeName = $route;
				$routePattern = str_replace('/', '\/', $route);
				if (count($matches)){
					$routeName = str_replace(array('{', '}'), '', $route);
					foreach ($matches as $key => $value){
						$args[] = '$' . $value[1];
						$argNames[] = $value[1];
						$routePattern = str_replace('{'.$value[1].'}', '(?P<'.$value[1].'>[^\/]+)', $routePattern);
					}
				}

				foreach ($endpoint as $contextName => $context){
					$contextChecks = $this->specs->contexts->$contextName;

					foreach ($context as $methodName => $method){
						$methodName = strtoupper($methodName);
						echo "\t". "function $methodName" . str_replace('/', '_', $routeName) . "_when_$contextName(" . implode(', ', $args) . ") {" . PHP_EOL;
						$this->routes[$routePattern] = "array(\"class\" => \"$resourceName\", \"method\" => \"" . str_replace('/', '_', $routeName) . "\", \"args\" => array(\"".implode('", "', $argNames)."\"))";

						//echo "\t\t" . "\$data = array();" . PHP_EOL;
						echo "\t\t" . "\$errors = array();" . PHP_EOL . PHP_EOL;

						$echoed = false;
						foreach ($args as $argName){
							if ($argName != '$data'){
								$echoed = true;
								echo "\t\t" . "\$data[\"".trim($argName, '$')."\"] = $argName;" . PHP_EOL;
							}
						}
						if ($echoed){
							echo PHP_EOL;
						}

						if (isset($method->delegate)){
							$delegate = explode('.', $method->delegate);
							echo "\t\t" . "return \Rocket::call(array(\"$delegate[0]\", \"$delegate[1]\"), \$data);" . PHP_EOL;
						}else{

							/*if (isset($method->queryParams)){
								echo "\t\t" . "// check query string data" . PHP_EOL;
								foreach ($method->queryParams as $paramName => $param){
									//echo "\t\t" . "\$$paramName = \$this->receive_$paramName(\$_GET[\"$paramName\"], \$errors);" . PHP_EOL;
								}
								echo PHP_EOL;
							}*/

							if (isset($method->expects)){
								echo "\t\t" . "// check for required input data" . PHP_EOL;
								foreach ($method->expects as $expectedName){
									$expected = $resource->properties->$expectedName;
									echo "\t\t" . "if (!isset(\$data[\"$expectedName\"])){ \$errors[] = \"$expectedName.required\"; }" . PHP_EOL;
									echo "\t\t" . "else{ \$data[\"$expectedName\"] = \$this->receive_$expectedName(\$data[\"$expectedName\"], \$errors); }" . PHP_EOL;
								}
								echo PHP_EOL;
							}

							if (isset($method->accepts)){
								echo "\t\t" . "// check optional input data if present" . PHP_EOL;
								foreach ($method->accepts as $acceptedName){
									echo "\t\t" . "if (isset(\$data[\"$acceptedName\"])){ \$data[\"$acceptedName\"] = \$this->receive_$acceptedName(\$data[\"$acceptedName\"], \$errors); }" . PHP_EOL;
								}
								echo PHP_EOL;
							}

							echo "\t\t" . 'if (count($errors)) {' . PHP_EOL;
							if (isset($method->traits)){
								foreach ($method->traits as $trait){
									if (method_exists($trait, 'on_error')){
										echo "\t\t" . "\Rocket::call(array(\"$trait\", \"on_error\"), \$data, \$errors);" . PHP_EOL;
									}
								}
							}
							if (isset($method->on_error)){
								$on_error = explode('.', $method->on_error);
								// TODO: pass data and errors
								//echo "\t\t\t" . "\$throwException = \Rocket::call(array(\"$on_error[0]\", \"$on_error[1]\"), \$data, \$errors);" . PHP_EOL;
								echo "\t\t\t" . "if (\Rocket::call(array(\"$on_error[0]\", \"$on_error[1]\"), \$data, \$errors)){" . PHP_EOL;
								echo "\t\t\t\t" . "throw new \InvalidInputDataException(\$errors);" . PHP_EOL;
								echo "\t\t\t" . "}" . PHP_EOL;
							}else{
								echo "\t\t\t" . "throw new \InvalidInputDataException(\$errors);" . PHP_EOL;
							}
							echo "\t\t" . '}' . PHP_EOL . PHP_EOL;

							$returnType = 'object';
							$schema = $method->returns;
							if (is_array($method->returns)){
								$returnType = 'collection';
								$schema = $method->returns[0];
							}else if (is_string($method->returns)){
								$returnType = 'relation';
								$schema = array();
							}

							$requestedFields = array();
							foreach ($schema as $key => $value){
								if (isset($resource->properties->$key) || $key == 'id'){
									$requestedFields[] = $key;
								}else{
									if (DEVELOPING){
										throw new \Exception('Requested field "'.$key.'" from "'.$resourceName.'" but field does not exist :(');
									}
								}
							}
							if (count($requestedFields) == 0){
								$requestedFields[] = '*';
							}

							if (isset($method->traits)){
								foreach ($method->traits as $trait){
									if (method_exists($trait, 'on_input')){
										echo "\t\t" . "\Rocket::call(array(\"$trait\", \"on_input\"), \$data);" . PHP_EOL;
									}
								}
							}
							if (isset($method->on_input)){
								$on_input = explode('.', $method->on_input);
								// TODO: pass data
								echo "\t\t" . "\Rocket::call(array(\"$on_input[0]\", \"$on_input[1]\"), \$data);" . PHP_EOL;
							}

							if (isset($method->on_action)){
								$on_action = explode('.', $method->on_action);
								echo "\t\t" . " return \Rocket::call(array(\"$on_action[0]\", \"$on_action[1]\"), \$data);" . PHP_EOL;
							}else{
								if ($methodName == "GET"){
									if ($returnType == 'object'){
										if (isset($method->sql)){
											echo "\t\t" . "\$query = \"{$method->sql}\";" . PHP_EOL;
										}else{
											echo "\t\t" . "\$query = \"SELECT ".implode(',', $requestedFields)." FROM $resourceName WHERE id = :id LIMIT 1\";" . PHP_EOL;
										}
										if (isset($method->traits)){
											foreach ($method->traits as $trait){
												if (method_exists($trait, 'on_query')){
													echo "\t\t" . "\Rocket::call(array(\"$trait\", \"on_query\"), \$query, \$data);" . PHP_EOL;
												}
											}
										}
										if (isset($method->on_query)){
											$on_query = explode('.', $method->on_query);
											// TODO: pass query ref and data
											echo "\t\t" . "\Rocket::call(array(\"$on_query[0]\", \"$on_query[1]\"), \$query, \$data);" . PHP_EOL;
										}

										//echo "\t\t" . "\$queryData = \$this->getDataForQuery(\$query, \$data);" . PHP_EOL;
										echo "\t\t" . "\$statement = \$this->db->prepare(\$query);" . PHP_EOL;
										echo "\t\t" . "\$statement->execute( \$this->getDataForQuery(\$query, \$data) );" . PHP_EOL;
										echo "\t\t" . "\$data = \$statement->fetch(\PDO::FETCH_ASSOC);" . PHP_EOL;
										echo "\t\t" . "if (!\$data){" . PHP_EOL;
										echo "\t\t\t" . "throw new \NotFoundException();" . PHP_EOL;
										echo "\t\t" . "}" . PHP_EOL;
									}else if ($returnType == 'collection'){
										if (isset($method->sql)){
											echo "\t\t" . "\$query = \"{$method->sql}\";" . PHP_EOL;
										}else{
											echo "\t\t" . "\$query = \"SELECT ".implode(',', $requestedFields)." FROM $resourceName\";" . PHP_EOL;
										}
										if (isset($method->traits)){
											foreach ($method->traits as $trait){
												if (method_exists($trait, 'on_query')){
													echo "\t\t" . "\Rocket::call(array(\"$trait\", \"on_query\"), \$query, \$data);" . PHP_EOL;
												}
											}
										}
										if (isset($method->on_query)){
											$on_query = explode('.', $method->on_query);
											// TODO: pass query ref and data
											echo "\t\t" . "\Rocket::call(array(\"$on_query[0]\", \"$on_query[1]\"), \$query, \$data);" . PHP_EOL;
										}
										//echo "\t\t" . "\$queryData = \$this->getDataForQuery(\$query, \$data);" . PHP_EOL;
										echo "\t\t" . "\$statement = \$this->db->prepare(\$query);" . PHP_EOL;
										echo "\t\t" . "\$statement->execute( \$this->getDataForQuery(\$query, \$data) );" . PHP_EOL;
										echo "\t\t" . "\$data = \$statement->fetchAll(\PDO::FETCH_ASSOC);" . PHP_EOL;
									}

									if ($returnType == 'relation'){
										// TODO: handle id...
										echo "\t\t" . "\$data = \$this->$method->returns(\$id);" . PHP_EOL;
									}
								}else if ($methodName == "POST"){
									if (isset($method->sql)){
										echo "\t\t" . "\$query = \"{$method->sql}\";" . PHP_EOL;
									}else{
										echo "\t\t" . "\$fields = array_intersect(\$this->fields, array_keys(\$data));" . PHP_EOL;
										echo "\t\t" . "\$query = \"INSERT INTO $resourceName (\".implode(',', \$fields).\") VALUES (:\".implode(', :', \$fields).\")\";" . PHP_EOL;
									}
									if (isset($method->traits)){
										foreach ($method->traits as $trait){
											if (method_exists($trait, 'on_query')){
												echo "\t\t" . "\Rocket::call(array(\"$trait\", \"on_query\"), \$query, \$data);" . PHP_EOL;
											}
										}
									}
									if (isset($method->on_query)){
										$on_query = explode('.', $method->on_query);
										echo "\t\t" . "\Rocket::call(array(\"$on_query[0]\", \"$on_query[1]\"), \$query, \$data);" . PHP_EOL;
									}
									echo "\t\t" . "\$statement = \$this->db->prepare(\$query);" . PHP_EOL;
									echo "\t\t" . "\$statement->execute( \$this->getDataForQuery(\$query, \$data) );" . PHP_EOL;
									echo "\t\t" . "\$id = \$this->db->lastInsertId();" . PHP_EOL;
									echo "\t\t" . "if (!\$id){" . PHP_EOL;
									echo "\t\t\t" . "throw new \Exception('Could not create resource');" . PHP_EOL;
									echo "\t\t" . "}" . PHP_EOL;
									echo "\t\t" . "\$query = \"SELECT * FROM $resourceName WHERE id = :id LIMIT 1\";" . PHP_EOL;
									echo "\t\t" . "\$statement = \$this->db->prepare(\$query);" . PHP_EOL;
									echo "\t\t" . "\$statement->execute(array(\"id\" => \$id));" . PHP_EOL;
									echo "\t\t" . "\$data = \$statement->fetch(\PDO::FETCH_ASSOC);" . PHP_EOL;
									echo "\t\t" . "if (!\$data){" . PHP_EOL;
									echo "\t\t\t" . "throw new \Exception('Could not create resource');" . PHP_EOL;
									echo "\t\t" . "}" . PHP_EOL;
									// TODO: how to better control response here? return Rocket::handle('/resources/id')?
								}else if ($methodName == "PUT"){
									if (isset($method->sql)){
										echo "\t\t" . "\$query = \"{$method->sql}\";" . PHP_EOL;
									}else{
										echo "\t\t" . "\$fields = array_intersect(\$this->fields, array_keys(\$data));" . PHP_EOL;
										echo "\t\t" . "\$pairs = array();" . PHP_EOL;
										echo "\t\t" . "foreach (\$fields as \$field){" . PHP_EOL;
										echo "\t\t\t" . "\$pairs[] = \$field . \" = :\" . \$field;" . PHP_EOL;
										echo "\t\t" . "}" . PHP_EOL;
										echo "\t\t" . "\$query = \"UPDATE $resourceName SET \".implode(', ', \$pairs).\" WHERE id = :id\";" . PHP_EOL;
									}
									if (isset($method->traits)){
										foreach ($method->traits as $trait){
											if (method_exists($trait, 'on_query')){
												echo "\t\t" . "\Rocket::call(array(\"$trait\", \"on_query\"), \$query, \$data);" . PHP_EOL;
											}
										}
									}
									if (isset($method->on_query)){
										$on_query = explode('.', $method->on_query);
										echo "\t\t" . "\Rocket::call(array(\"$on_query[0]\", \"$on_query[1]\"), \$query, \$data);" . PHP_EOL;
									}
									echo "\t\t" . "\$statement = \$this->db->prepare(\$query);" . PHP_EOL;
									echo "\t\t" . "\$result = \$statement->execute( \$this->getDataForQuery(\$query, \$data) );" . PHP_EOL;
									echo "\t\t" . "if (!\$result){" . PHP_EOL;
									echo "\t\t\t" . "throw new \Exception('Could not update resource');" . PHP_EOL;
									echo "\t\t" . "}" . PHP_EOL;
								}

								if (isset($method->traits)){
									foreach ($method->traits as $trait){
										if (method_exists($trait, 'on_data')){
											echo "\t\t" . "\Rocket::call(array(\"$trait\", \"on_data\"), \$data);" . PHP_EOL;
										}
									}
								}
								if (isset($method->on_data)){
									$on_data = explode('.', $method->on_data);
									// TODO: pass data
									echo "\t\t" . "\Rocket::call(array(\"$on_data[0]\", \"$on_data[1]\"), \$data);" . PHP_EOL;
								}

								echo "\t\t" . "return \$data;" . PHP_EOL;
							}
						}
						echo "\t}" . PHP_EOL . PHP_EOL;
					}
				}
			}

			echo "}";

			$src = ob_get_contents();
			ob_end_clean();

			if (!file_exists($this->system->config['core_path'] . 'resources/')){
				mkdir($this->system->config['core_path'] . 'resources/', 0755, true);
			}
			file_put_contents($this->system->config['core_path'] . 'resources' . DIRECTORY_SEPARATOR . $resourceName . '.php', '<?php ' . PHP_EOL . $src);
		}
	}

	public function generateRoutes()
	{
		//echo 'generating routes'.PHP_EOL;
		$routesWithoutPlaceholder = array();
		$routesWithPlaceholder = array();
		$routes = array();

		foreach ($this->routes as $route => $method){
			if (stripos($route, '(?P<') !== false){
				$routesWithPlaceholder[] = '"'.$route.'" => '.$method;
			}else{
				$routesWithoutPlaceholder[] = '"'.$route.'" => '.$method;
			}
		}
		$routes = array_merge($routesWithoutPlaceholder, $routesWithPlaceholder);

		$routes = '<?php return array(' . PHP_EOL . implode(',' . PHP_EOL, $routes) . PHP_EOL . ');';
		file_put_contents($this->system->config['core_path'] . 'routes.php', $routes);
	}

	protected function getResource($resourceName){
		foreach ($this->specs->resources as $targetName => $target){
			if ($resourceName == $targetName){
				return $target;
			}
		}
		return null;
	}

	protected function schema($resourceName, $resource){
		$schema = array();
		// Auto create id fields for all resources
		$schema[$resourceName.'.id'] = array(
			'Table' => $resourceName,
			'Field' => 'id',
			'Type' => 'int(11)',
			'Null' => 'NO',
			'Key' => 'PRI',
			'Default' => NULL,
			'Extra' => 'auto_increment'
		);

		foreach ($resource->properties as $key => $value){
			if (!is_object($value->type)){
				// Basic data fields
				$schema[$resourceName.'.'.$key] = array(
					'Table' => $resourceName,
					'Field' => $this->guessField($key, $value),
					'Type' => $this->guessType($key, $value),
					'Null' => $this->guessNull($key, $value),
					'Key' => $this->guessKey($key, $value),
					'Default' => $this->guessDefault($key, $value),
					'Extra' => $this->guessExtra($key, $value)
				);
			}
		}

		foreach ($resource->properties as $key => $value){

			if (is_object($value->type)){
				$target = $this->getResource($value->type->resource);
				$targetProperty = $target->properties->{$value->type->on};

				if ($value->type->relation == 'has-one'){

					if ($targetProperty->type->relation == 'has-many'){
						$schema[$resourceName.'.'.$key . '_id'] = array(
							'Table' => $resourceName,
							'Field' => $key . '_id',
							'Type' => 'int(11)',
							'Null' => 'YES',
							'Key' => '',
							'Default' => NULL,
							'Extra' => ''
						);

						continue;
					}else{
						$resources = array($value->type->resource, $resourceName);
						sort($resources);

						if ($resourceName == $resources[0]){
							$schema[$resourceName.'.'.$key . '_id'] = array(
								'Table' => $resourceName,
								'Field' => $key . '_id',
								'Type' => 'int(11)',
								'Null' => 'YES',
								'Key' => '',
								'Default' => NULL,
								'Extra' => ''
							);

							continue;
						}
					}
				}

				if ($value->type->relation == 'has-many'){
					
					if ($targetProperty->type->relation == 'has-many'){
						$resources = array($key, $value->type->on);
						sort($resources);

						$schema[$resources[0].'_'.$resources[1].'.id'] = array(
							'Table' => $resources[0].'_'.$resources[1],
							'Field' => 'id',
							'Type' => 'int(11)',
							'Null' => 'NO',
							'Key' => 'PRI',
							'Default' => NULL,
							'Extra' => 'auto_increment'
						);

						$schema[$resources[0].'_'.$resources[1].'.'.$resources[0]] = array(
							'Table' => $resources[0].'_'.$resources[1],
							'Field' => $resources[0].'_id',
							'Type' => 'int(11)',
							'Null' => 'NO',
							'Key' => '',
							'Default' => NULL,
							'Extra' => ''
						);

						$schema[$resources[0].'_'.$resources[1].'.'.$resources[1]] = array(
							'Table' => $resources[0].'_'.$resources[1],
							'Field' => $resources[1].'_id',
							'Type' => 'int(11)',
							'Null' => 'NO',
							'Key' => '',
							'Default' => NULL,
							'Extra' => ''
						);
						continue;
					}
					/*$schema[$value->type->resource.'.'.$key . '_id'] = array(
						'Table' => $value->type->resource,
						'Field' => $key . '_id',
						'Type' => 'int(11)',
						'Null' => 'YES',
						'Key' => '',
						'Default' => NULL,
						'Extra' => ''
					);*/

				}
			}
		}
		//print_r($schema);
		return $schema;
	}

	private function guessField($name, $value)
	{
		return $name;
	}

	private function guessType($name, $value)
	{
		if ($value->type == 'string' || $value->type == 'email'){
			if (!isset($value->max_length)){
				$value->max_length = 50;
			}

			if ($value->max_length > 16777215){
				return 'LONGTEXT';
			}else if ($value->max_length > 65535){
				return 'MEDIUMTEXT';
			}else if ($value->max_length > 255){
				return 'TEXT';
			}else{
				if (isset($value->min_length) && $value->min_length == $value->max_length){
					return 'char('.$value->max_length.')';
				}
				return 'varchar('.$value->max_length.')';
			}
		}

		if (in_array($value->type, array('int','float','double','decimal'))){
			$value->max = isset($value->max) ? $value->max : 65535;
			$value->min = isset($value->min) ? $value->max : 0;

			if ($value->type == 'int'){
				$unsigned = true;
				if (isset($value->min) && $value->min < 0 || $value->max < 0){
					$unsigned = false;
				}

				if ($unsigned){
					if ($value->max > 4294967295){
						$return = 'BIGINT';
					}else if ($value->max > 16777215){
						$return = 'INT';
					}else if ($value->max > 65535){
						$return = 'MEDIUMINT';
					}else if ($value->max > 255){
						$return = 'SMALLINT';
					}else{
						$return = 'TINYINT(3)';
					}
				}else{
					if ($value->max > 2147483647 || $value->min < -2147483648){
						$return = 'BIGINT';
					}else if ($value->max > 8388607 || $value->min < -8388608){
						$return = 'INT';
					}else if ($value->max > 32767 || $value->min < -32768){
						$return = 'MEDIUMINT';
					}else if ($value->max > 127 || $value->min < -128){
						$return = 'SMALLINT';
					}else{
						$return = 'TINYINT';
					}
				}

				return $return . ($unsigned ? ' unsigned' : '');
			}

			$length = strlen($value->max);
			$decimals = isset($value->decimals) ? $value->decimals : 2;

			// TODO: auto guess type based on max/length/decimal ratio?
			if ($value->type == 'float'){
				return 'FLOAT('.$length.','.$decimals.')';
			}

			if ($value->type == 'double'){
				return 'DOUBLE('.$length.','.$decimals.')';
			}

			if ($value->type == 'decimal'){
				return 'DECIMAL('.$length.','.$decimals.')';
			}
		}

		if ($value->type == 'date'){
			return 'DATE';
		}

		if ($value->type == 'datetime'){
			return 'DATETIME';
		}

		if ($value->type == 'time'){
			return 'TIME';
		}

		if ($value->type == 'bool'){
			return 'TINYINY(1)';
		}

		if (is_object($value->type)){
			// assume it is a relation
			return 'relation';
		}

		throw new \Exception('Unrecognized type "'.$value->type.'"');
	}

	private function guessNull($name, $value)
	{
		if ($name == 'id'){
			return 'NO';
		}else if (isset($value->default)){
			return 'NO';
		}
		return 'YES';
	}

	private function guessKey($name, $value)
	{
		if ($name == 'id'){
			return 'PRI';
		}else if (isset($value->unique) && $value->unique == true){
			return 'UNI';
		}
		return '';
	}

	private function guessDefault($name, $value)
	{
		if ($value->type == 'bool'){
			return isset($value->default) ? $value->default : 0;
		}

		if (in_array($value->type, array('float', 'double', 'decimal'))){
			if (isset($value->default)){
				$decimals = isset($value->decimals) ? $value->decimals : 2;
				return number_format($value->default, $decimals);
			}
		}

		if ($value->type == 'datetime'){
			if (isset($value->default)){
				return $value->default;
			}
		}

		if (isset($value->default)){
			return $value->default;
		}

		return NULL;
	}

	private function guessExtra($name, $value)
	{
		if ($name == 'id'){
			return 'auto_increment';
		}
		return '';
	}

	public function syncDb()
	{

		$sync = array(
			'create' => array(),
			'delete' => array(),
			'update' => array(),
			'changes' => 0
		);
		$existingTables = $this->system->db->tables(true);
		$models = array();

		foreach ($this->specs->resources as $resourceName => $resource){
			$models[$resourceName] = $resource;
		}

		$m = array(); // model data structure
		$d = array(); // database data structure
		foreach ($models as $modelName => $model){
			$modelSchema = $this->schema($modelName, $model);
			foreach ($modelSchema as $field){
				$m[$field['Table']][$field['Field']] = $field;
			}
		}

//echo '<pre>';print_r($m);echo '</pre>';

		// loop over existing tables
		foreach ($existingTables as $tableName){
			if (in_array($tableName, $this->system->config['ignored_tables'])){
				continue;
			}
			$dbSchema = $this->system->db->schema($tableName);
			$d[$tableName] = $dbSchema;
		}

//echo '<pre>';print_r($d);echo '</pre>';

		$targetSchema = array();
		
		// loop over model required tables
		foreach ($m as $mTable){
			// loop over each field in the table specified by the model
			foreach ($mTable as $mField){
				// keep these for later
				$tableName = $mField['Table'];
				$fieldName = $mField['Field'];

				// we need a table named $tableName, that has a field named $fieldName that conforms to $mField
				$targetSchema[$tableName][$fieldName] = $mField;

				// check if table exists in database
				if (array_key_exists($tableName, $d)){
					// check if field exists in table on database
					if (array_key_exists($fieldName, $d[$tableName])){
						// field exists, then compare every structure item to see if it changed
						foreach ($mField as $key => $value){
							// compare structure items, not case sensitive!
							if (strcasecmp($d[$tableName][$fieldName][$key], $value) != 0){
								// items are different, update field
								$sync['changes']++;
								$sync['update'][$tableName][$fieldName] = $mField;
								$sync['update'][$tableName][$fieldName]['previous'] = $d[$tableName][$fieldName];
							}
						}
					}else{
						// field does not exist, create it
						$sync['changes']++;
						$sync['create'][$tableName][$fieldName] = $mField;
					}
				}else{
					// table does not exist, create it
					$sync['changes']++;
					$sync['create'][$tableName] = $mTable;
				}
			}
		}

		// now we check for stuff to delete (is on database but not model)
		// loop over all database tables
		foreach ($d as $dTable){
			// loop over all database table fields
			foreach ($dTable as $dField){
				// keep these for later
				$tableName = $dField['Table'];
				$fieldName = $dField['Field'];

				// if table exists in target schema
				if (array_key_exists($tableName, $targetSchema)){
					// if field exists in target schema
					if (array_key_exists($fieldName, $targetSchema[$tableName])){
						// do nothing
					}else{
						// delete field
						$sync['changes']++;
						$sync['delete'][$tableName][$fieldName] = $dField;
					}
				}else{
					// delete table
					$sync['changes']++;
					$sync['delete'][$tableName] = true;
				}
			}
		}

		// all done here
		//print_r($sync);
		return $sync;
	}

	private function sync($sync)
	{
		foreach ($sync['create'] as $tableName => $table){

			if (!$this->system->db->tableExists($tableName)){
				echo 'Create Table: '.$tableName.'<br>';
				$this->system->db->createTable($tableName);
			}

			foreach ($table as $fieldName => $field){
				echo 'Create Field: '. $fieldName.'<br>';
				if ($fieldName == 'id'){
					$dbSchema = $this->system->db->schema($tableName);
					if (array_key_exists($fieldName, $dbSchema)){
						// by convention, all models have an 'id' field that is created
						// on table creation. Therefore adding an existing column will throw
						// an error. TODO: validate if this check should happen to all fields
						continue;
					}
				}
				$this->system->db->addField($field);
			}
		}

		foreach ($sync['delete'] as $tableName => $table){
			if (!is_array($table)){
				$this->system->db->deleteTable($tableName);
			}else{
				foreach ($table as $fieldName => $field){
					$this->system->db->dropField($field);
				}
			}
		}

		foreach ($sync['update'] as $tableName => $table){
			foreach ($table as $fieldName => $field){
				$this->system->db->modifyField($field);
			}
		}
	}
}