<?php
namespace BitwardenOrgSync;

class HttpClient {

    private $url;
    private $post = false;
    private $postfields = [];
    private $headers = [];
    private $returntransfer = true;
    private $timeout = 10;
    private $verifyhost = false;
    private $verifypeer = false;
    private $followlocation = true;
    private $custommethod = false;
    private $useCookies;
    private $statusCode;
    private $result;
    private $verbose = false;

    public function setUrl(string $value) {
        $this->url = $value;
        return $this;
    }

    public function setPost(bool $value) {
        $this->post = $value;
        return $this;
    }

    public function setPostFields(array $value, $json = false) {
        $this->postfields = $value;
        if ($json) {
            $this->postfields = json_encode($value);
        }
        return $this;
    }

    public function setHeaders(array $value) {
        $this->headers = $value;
        return $this;
    }

    public function setReturnTransfer(bool $value) {
        $this->returntransfer = $value;
        return $this;
    }

    public function setTimeout(int $value) {
        $this->timeout = $value;
        return $this;
    }

    public function setVerifyHost(bool $value) {
        $this->verifyhost = $value;
        return $this;
    }

    public function setVerifyPeer(bool $value) {
        $this->verifypeer = $value;
        return $this;
    }

    public function setFollowLocation(bool $value) {
        $this->followlocation = $value;
        return $this;
    }

    public function useCookies() {
        $this->useCookies = true;
        $this->cookieHash = md5(time());
        return $this;
    }

    public function setCustomMethod(string $method) {
        $this->custommethod = $method;
        return $this;
    }

    public function setVerbose(bool $value) {
        $this->verbose = $value;
    }

    public function exec() {
        $this->result = $this->call();
        return $this;
    }

    public function getStatusCode() {
        return $this->statusCode;
    }

    public function jsonToArray() {
        return json_decode($this->result, true);
    }

    public function result() {
        return $this->result;
    }

    private function call() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_POST, $this->post);
        if ($this->post) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->postfields);
            //var_dump($this->postfields);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, $this->returntransfer);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyhost);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifypeer);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_VERBOSE, $this->verbose);


        if ($this->custommethod) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->custommethod);
        }

        if ($this->useCookies) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, "/tmp/httpClinetCookies-" . $this->cookieHash . ".txt");
            curl_setopt($ch, CURLOPT_COOKIEFILE, "/tmp/httpClinetCookies-" . $this->cookieHash . ".txt");
        }

        $exec = curl_exec($ch);
        $this->statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return $exec;
    }
}
