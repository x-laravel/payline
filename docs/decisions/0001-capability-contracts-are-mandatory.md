# 0001 Capability Contracts Are Mandatory

## Context

Providers support different operations. One takes a charge but no pre authorization, another settles refunds but has no webhook channel. Payline has to know which operations a gateway can perform in order to route a payment to a provider that can take it, and in order to refuse an operation before it writes a row.

Duck typing answers the question cheaply: check whether the gateway defines a method with the right name. It also answers it wrongly. A private helper, a magic `__call`, or a method with a different signature all look like support. A gateway that returns a `NOT_SUPPORTED` response from a stub has a real transaction row written for an operation that was never possible, and routing believes the provider is a candidate.

## Decision

`Gateway` carries only identity. Every operation lives in its own interface, and Payline calls a gateway method only after an `instanceof` check against that interface. `TransactionType` holds the mapping from operation to contract and method name, so there is one place where it is written down.

A gateway declares support by implementing interfaces and declares the absence of support by not implementing them. There is no fallback to method detection.

## Consequences

Routing can ask what a gateway supports without calling the provider, and the answer is the same one the invoker will act on.

An operation a gateway cannot perform throws a `LogicException` before a transaction row exists, so the transactions table holds real provider calls only.

Adding a method to a gateway is not enough to enable an operation. A gateway author who forgets the interface gets an exception naming the interface and the method. Existing gateways that relied on method detection must be updated before they work with this version.

## Status

Accepted.
