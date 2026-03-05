<?php

namespace PostStation\Services\Workflow;

class WorkflowContext
{
	/** @var array<string,mixed> */
	private array $data = [];

	/**
	 * @param array<string,mixed> $seed
	 */
	public function __construct(array $seed = [])
	{
		$this->data = $seed;
	}

	/**
	 * @return mixed
	 */
	public function get(string $key, $default = null)
	{
		return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
	}

	/**
	 * @param mixed $value
	 */
	public function set(string $key, $value): void
	{
		$this->data[$key] = $value;
	}

	public function remove(string $key): void
	{
		unset($this->data[$key]);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function replace(array $data): void
	{
		$this->data = $data;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array
	{
		return $this->data;
	}
}
