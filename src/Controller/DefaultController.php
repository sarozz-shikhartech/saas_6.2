<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends AbstractController
{

    private EntityManagerInterface $entityManager;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /*
     * @todo Route modified as we need storeId and member badge id instead of member id
     */
    #[Route("/store/{storeId}/member/{memberBadgeId}/catalog/{giftCatalogId}/gift/{giftId}/v2", name: 'member_gift_catalog_detail_v2', methods: ['GET'])]
    public function saasGiftCatalogDetailV2Api(Request $request): JsonResponse
    {
        $conn = $this->entityManager->getConnection();
        $today = (new \DateTime())->format('Y-m-d H:i:s');

        $memberBadgeId = $request->get('memberBadgeId');
        $catalogId = $request->get('giftCatalogId');
        $giftId = $request->get('giftId');
        $storeId = $request->get('storeId');

        $conn->prepare('SET sql_mode=(SELECT REPLACE(@@sql_mode, "ONLY_FULL_GROUP_BY", ""))')->executeQuery();

        $sql = "
            SELECT 
                c.id, c.public_name, c.public_description, c.shipping_type, c.is_for_display, c.is_retail, c.is_global, c.retail_price, c.is_pre_sale, 
                c.pre_sale_limit, c.is_external, 
                c.external_link, c.inventory_owner_id, c.inventory, c.print_item, 1 as gift_item,
                gi.*,
                ci.*,
                (SELECT GROUP_CONCAT(DISTINCT sticker) FROM catalog_sticker WHERE catalog_sticker.catalog_id = c.id) AS stickers,
                IFNULL(CONCAT('[', GROUP_CONCAT(DISTINCT IF(ci2.deleted_at IS NULL, JSON_OBJECT(
                                    'image', ci2.image,
                                    'is_primary', ci2.is_primary,
                                    'status', ci2.status,
                                    'color_id', ci2.color_id,
                                    'code', (SELECT code FROM attribute WHERE id = ci2.color_id),
                                    'color', (SELECT name FROM attribute WHERE id = ci2.color_id)
                                    ), NULL)), ']'), '[]') AS images
            FROM
                catalog AS c
                LEFT JOIN catalog_image ci2 ON ci2.catalog_id = c.id
                INNER JOIN (SELECT IFNULL(CONCAT('[', GROUP_CONCAT(DISTINCT JSON_OBJECT('id', catalog_item.id, 
                                                              'sku_number', catalog_item.sku_number, 
                                                              'weight', catalog_item.weight, 
                                                              'size', catalog_item.size, 
                                                              'size_id', catalog_item.size_id, 
                                                              'color', catalog_item.color, 
                                                              'color_id', catalog_item.color_id, 
                                                              'a2s', catalog_item.a2s, 
                                                              'presale_inventory', catalog_item.presale_inventory,
                                                              'color_code', (SELECT code FROM attribute WHERE id = catalog_item.color_id), 
                                                              'size_list_order', (SELECT list_order FROM attribute WHERE id = catalog_item.size_id),
                                                              'color_list_order', (SELECT list_order FROM attribute WHERE id = catalog_item.color_id),
                                                              'status', catalog_item.status)) ,']'), '[]') AS items FROM catalog_item 
                                                                                                             WHERE catalog_item.catalog_id = $catalogId 
                                                                                                               AND catalog_item.item_status = '1') ci
                INNER JOIN (SELECT id AS gift_id,
                                   CASE WHEN primary_gift = $catalogId THEN 1
                                        WHEN secondary_gift = $catalogId THEN 2
                                    ELSE 0
                                   END as gift_type,
                                   CASE WHEN primary_gift = $catalogId THEN primary_gift_qty
                                        WHEN secondary_gift = $catalogId THEN secondary_gift_qty
                                    ELSE 0
                                   END as gift_qty 
                                   FROM gift WHERE gift.id = $giftId) gi
            WHERE
                c.id = $catalogId 
            AND c.status = 1
            AND EXISTS (SELECT id FROM gift WHERE id = $giftId 
                                              AND (primary_gift = c.id OR secondary_gift = c.id) 
                                              AND status = 0 
                                              AND badge_id = '$memberBadgeId' 
                                              AND expiry_date > '$today')
            GROUP BY c.id;
        ";

        $catalog = $conn->prepare($sql)->executeQuery()->fetchAllAssociative();
        if (!isset($catalog[0])) return $this->error("Gift does not exist. Either it has been expired or you have already claimed it.");

        $sql2 = "
            SELECT 
                a2.name as parent, json_arrayagg(ca.name) as child
            FROM
                catalog_attribute ca
                    LEFT JOIN
                attribute a2 ON ca.attribute_parent_id = a2.id 
            WHERE ca.catalog_id = $catalogId 
            GROUP BY a2.name;
        ";

        $attributes = $conn->prepare($sql2)->executeQuery()->fetchAllAssociative();

        $catalog[0]['attributes'] = $attributes;
        $catalog[0]['images'] = json_decode($catalog[0]['images'], true);
        $catalog[0]['items'] = json_decode($catalog[0]['items'], true);

        if ($catalog[0]['inventory_owner_id'] !== 0) {
            $inventoryOwnerId = $catalog[0]['inventory_owner_id'];
            $sql3 = "
                SELECT * FROM inventory_owner WHERE inventory_owner.store_id = $storeId AND inventory_owner.id = $inventoryOwnerId;
            ";
            $inventoryOwner = $conn->prepare($sql3)->executeQuery()->fetchAssociative();
            if ($inventoryOwner) {
                $apiToken = $inventoryOwner['api_token'];
            }
        }

        $checkInventory = 0;
        $checkAddToCart = true;
        $catalogItemData = $catalog[0]['items'];

        $skuNumberColumns = array_column($catalog[0]['items'], "sku_number");

        if ($catalog[0]['is_pre_sale'] === '0') {
            $jsonData['itemSkuNumbers'] = $skuNumberColumns;

            $client = HttpClient::create();
            $response = $client->request('POST', $this->getParameter('voxships.api_url') . '/inventoryItems/A2S', [
                'headers' => [
                    'Accept' => 'application/json',
                    'apiToken' => $apiToken,
                ],
                'json' => $jsonData,
            ]);

            $returnData = $response->toArray();
            if ($returnData['returnType'] == 'success') {
                $customerItems = $returnData['result']['customerItems'];
                foreach ($customerItems as $customerItem) {
                    $inventoryFromVoxship = (int)$customerItem['A2S'];
                    $checkInventory += $inventoryFromVoxship;

                    $key = array_search($customerItem['itemSkuNumber'], $skuNumberColumns, true);

                    if (isset($catalog[0]['items'][$key])) {
                        $catalog[0]['items'][$key]['total_inventory'] = $inventoryFromVoxship;
                        $catalog[0]['items'][$key]['status'] = $customerItem['status'];
                    }
                }
                if ($checkInventory <= 0) {
                    $checkAddToCart = false;
                }
            } else {
                $checkAddToCart = false;
            }
        } else {
            foreach ($catalogItemData as $item) {
                if ($catalog[0]['pre_sale_limit'] === '1') {
                    $inventoryFromStore = (int)$item['presale_inventory'];
                } else {
                    $inventoryFromStore = 100;
                }

                $checkInventory += $inventoryFromStore;
                $key = array_search($item['sku_number'], $skuNumberColumns, true);

                if (isset($catalog[0]['items'][$key] )) {
                    $catalog[0]['items'][$key]['total_inventory'] = $inventoryFromStore;
                    $catalog[0]['items'][$key]['status'] = '1';
                }
            }
            if ($checkInventory <= 0) {
                $checkAddToCart = false;
            }
        }

        $data['checkAddToCart'] = $checkAddToCart;
        $data['catalog'] = $catalog[0];

        return $this->json($data);
    }
}