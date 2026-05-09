<?php

namespace App\Repository;

use App\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    public function findByUuid(string $uuid): ?Invoice
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Lower-case recipient addresses across every still-payable invoice.
     * Used by the chain listener to compute the topics[2] filter for eth_getLogs.
     *
     * @return string[] e.g. ['0x742d35cc...', '0xabcdef12...']
     */
    public function getOpenRecipientAddresses(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('DISTINCT LOWER(i.recipientAddress) AS addr')
            ->where('i.status IN (:open)')
            ->setParameter('open', ['sent', 'viewed', 'partially_paid', 'overdue'])
            ->getQuery()
            ->getResult();
        return array_map(static fn(array $r): string => $r['addr'], $rows);
    }

    /**
     * @return Invoice[]
     */
    public function findOpenByRecipient(string $address): array
    {
        return $this->createQueryBuilder('i')
            ->where('LOWER(i.recipientAddress) = :addr')
            ->andWhere('i.status IN (:open)')
            ->setParameter('addr', strtolower($address))
            ->setParameter('open', ['sent', 'viewed', 'partially_paid', 'overdue'])
            ->orderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Invoice $invoice, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($invoice);
        if ($flush) {
            $em->flush();
        }
    }
}
