<?php

namespace App\Entity;

use App\Repository\RuleAddressRepository;
use App\Util\Email;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'mailaddr')]
#[ORM\UniqueConstraint(name: 'email', columns: ['email'])]
#[ORM\Entity(repositoryClass: RuleAddressRepository::class)]
class RuleAddress
{
    #[ORM\Column(name: 'id', type: 'integer', nullable: false, options: ['unsigned' => true])]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private int $id;

    #[ORM\Column(name: 'priority', type: 'integer', nullable: false, options: ['default' => 7])]
    private int $priority = 7;


    #[ORM\Column(name: 'email', type: Types::BINARY, length: 255, nullable: false)]
    private string $email;

    /**
     * When the domain of this address was first seen missing from the DNS
     * (see the agentj:clean-sender-rules command), null if it exists.
     */
    #[ORM\Column(name: 'dns_failed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dnsFailedAt = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = Email::normalize($email);

        return $this;
    }

    public function getDnsFailedAt(): ?\DateTimeImmutable
    {
        return $this->dnsFailedAt;
    }

    public function setDnsFailedAt(?\DateTimeImmutable $dnsFailedAt): self
    {
        $this->dnsFailedAt = $dnsFailedAt;

        return $this;
    }
}
