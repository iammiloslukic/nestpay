<?php

namespace ReadyCMSIO\Nestpay;

class MerchantConfig implements \ArrayAccess, \JsonSerializable {
    
    protected $config = [
        'clientId' => '',
        'storeKey' => '',
        'storeType' => '3D_PAY_HOSTING',
        'okUrl' => '',
        'failUrl' => '',
        '3DGateUrl' => '',
        
        //API
        'apiName' => '',
        'apiPassword' => '',
        'apiEndpointUrl' => ''
    ];
    
    function __construct(array $config = null) {
        if (!is_null($config)) {
            $this->setConfig($config);
        }
    }

    public function offsetExists(mixed $offset): bool {
        return array_key_exists($offset, $this->config);
    }

    public function offsetGet(mixed $offset): mixed {
        return isset($this->config[$offset]) ? $this->config[$offset] : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        if (!is_scalar($value) || is_null($value)) {
            throw new \InvalidArgumentException('Argument $value must be scalar got ' . (is_array($value) ? 'array' : (is_null($value) ? 'null' : get_class($value))));
        }
        $this->config[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void {
        if (isset($this->config[$offset])) {
            unset($this->config[$offset]);
        }
    }

    /**
     * @param array $config
     * @return \ReadyCMSIO\Nestpay\MerchantConfig
     * @throws \InvalidArgumentException
     */
    public function setConfig(array $config): self {
        foreach ($config as $key => $val) {
            if (!is_string($key) || empty($key)) {
                throw new \InvalidArgumentException('Keys of array $config must be non empty strings got: ' . $key);
            }
            $this->offsetSet($key, $val);
        }
        return $this;
    }
    
    /**
     * @return array
     */
    public function toArray(): array {
        return $this->config;
    }

    /**
     * @return mixed
     */
    public function jsonSerialize(): mixed {
        return $this->toArray();
    }

    /**
     * @return string
     */
    public function getClientId(): string {
        return $this->offsetGet('clientId');
    }

    /**
     * @return string
     */
    public function getStoreKey(): string {
        return $this->offsetGet('storeKey');
    }

    /**
     * @return string
     */
    public function getStoreType(): string {
        return $this->offsetGet('storeType');
    }

    /**
     * @return string
     */
    public function getOkUrl(): string {
        return $this->offsetGet('okUrl');
    }

    /**
     * @return string
     */
    public function getFailUrl(): string {
        return $this->offsetGet('failUrl');
    }

    /**
     * @return string
     */
    public function get3DGateUrl(): string {
        return $this->offsetGet('3DGateUrl');
    }

    /**
     * @return string
     */
    public function getApiName(): string {
        return $this->offsetGet('apiName');
    }

    /**
     * @return string
     */
    public function getApiPassword(): string {
        return $this->offsetGet('apiPassword');
    }

    /**
     * @return string
     */
    public function getApiEndpointUrl(): string {
        return $this->offsetGet('apiEndpointUrl');
    }
}
