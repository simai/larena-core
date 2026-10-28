<?php

declare(strict_types=1);

namespace Larena\Core\Gateway;

use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcessSpecification;

/**
 * The port one external command gateway implements. The adapter knows how a request is carried to its gateway and how
 * the answer is read back; the runner owns the checks around it, so an adapter cannot widen what it may run.
 */
interface GatewayAdapter
{
    public function manifest(): GatewayManifest;

    /** The one process invocation that carries this request, already allowed by the manifest, to the gateway. */
    public function invocation(GatewayRequest $request): ProcessSpecification;

    /**
     * Reads the gateway's answer from the finished process. A gateway may report a refusal with a non-zero exit, so
     * the exit code is the adapter's to interpret; an unreadable answer is `gateway_answer_invalid`.
     *
     * @throws GatewayRejected
     */
    public function answer(GatewayRequest $request, ProcessResult $result): GatewayAnswer;
}
