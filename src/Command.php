<?php
namespace lecodeurdudimanche\Processes;


class Command {

    private $stdin;
    private $stdout;
    private $stderr;
    private $handle;
    private $command;

    public function __construct($command)
    {
        $this->command = $command;
    }

    public function launch() : void
    {
        $streamDescriptors = [
            array("pipe", "r"),
            array("pipe", "w"),
            array("pipe", "w")
        ];
        $this->handle = proc_open($this->command, $streamDescriptors, $pipes, NULL, NULL, ["bypass_shell" => true]);
        list($this->stdin, $this->stdout, $this->stderr) = $pipes;


        if ($this->handle === false)
            throw new Exceptions\ProcessException($this->command, "Could not launch");

        //This is can avoid deadlock on some cases (when stderr buffer is filled up before writing to stdout and vice-versa)
        stream_set_blocking($this->stdout, 0);
        stream_set_blocking($this->stderr, 0);
    }

    private function getNextStreamLine($stream) : ?string
    {
        $string = fgets($stream, 8192);
        return $string === false ? null : $string;
    }

    public function getNextLine() : ?string
    {
        return $this->getNextStreamLine($this->stdout);
    }

    public function getNextErrorLine() : ?string
    {
        return $this->getNextStreamLine($this->stderr);
    }

    public function writeString(string $str) : bool
    {
        $str .= "\n";
        return $this->write($str, strlen($str));
    }

    public function isRunning(): bool
    {
        if (! $this->handle)
            return false;

        $procInfo = proc_get_status($this->handle);
        return $procInfo["running"];
    }


    public function execute(?array $initialData = null, ?callable $processData = null, int $refreshFrequency = 25): array
    {
        $this->launch();

        $running = true;
        $data = ["out" => "", "err" => ""];

        $sleepTime = 1000000 / $refreshFrequency;

        if ($initialData)
        {
            foreach ($initialData as $string)
                $this->writeString($string);
        }

        while ($running === true)
        {
            $line = fgets($this->stdout, 8192);
            if ($line && $processData)
            {
                $response = $processData($line);
                $this->writeString($response);
                echo $line;
            }

            $data["out"] .= $line;
            $data["err"] .= fread($this->stderr, 8192);

            $running = $this->isRunning();

            usleep($sleepTime);
        }

        while ($line = fgets($this->stdout, 8192))
            $data["out"] .= $line;
        while ($line = fgets($this->stderr, 8192))
            $data["err"] .= $line;

        $this->close();

        return $data;
    }

    private static function getSelfAndChildrenOf(int $pid) : array
    {
        $res = (new Command("ps --ppid $pid --no-headers -o pid|tr -s '\n' ' '"))->execute()['out'];
        $pids = [$pid];

        if (!trim($res))
            return $pids;

        foreach(explode(' ', $res) as $pid)
        {
            $pid = intval($pid);
            if (! $pid)
                continue;
            $pids = array_merge($pids, self::getSelfAndChildrenOf($pid));
        }
        return $pids;
    }

    public function getSelfAndChildrenPID() : array
    {
        return self::getSelfAndChildrenOf($this->getPID());
    }

    public function getPID() : int
    {
        return proc_get_status($this->handle)["pid"];
    }

    private function signalAllChildren(int $sig) : void
    {
        foreach($this->getSelfAndChildrenPID() as $pid)
            posix_kill($pid, $sig);
    }

    public function pause() : void
    {
        $this->signalAllChildren(SIGSTOP);
    }

    public function resume() : void
    {
        $this->signalAllChildren(SIGCONT);
    }

    public function terminate() : int
    {
        $this->signalAllChildren(SIGKILL);
        //posix_kill($this->getPID(), SIGKILL);
        return $this->close();
    }

    public function close(): int
    {
        $this->closeStream($this->stdin);
        $this->closeStream($this->stdout);
        $this->closeStream($this->stderr);
        return $this->handle ? proc_close($this->handle) : -1;
    }

    public function closeStdin(): void
    {
        $this->closeStream($this->stdin);
    }

    private function write($data, int $len) : bool
    {
        $total = 0;
        do
        {
            $res = fwrite($this->stdin, substr($data, $total));
        } while($res && $total += $res < $len);
        return $total === $len;
    }

    private function closeStream(&$stream) : void
    {
        if ($stream !== NULL)
        {
            fclose($stream);
            $stream = NULL;
        }
    }
}
