<?php

final class PhortuneAccountViewController extends PhortuneController {

  private $accountID;

  public function willProcessRequest(array $data) {
    $this->accountID = $data['accountID'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    // TODO: Currently, you must be able to edit an account to view the detail
    // page, because the account must be broadly visible so merchants can
    // process orders but merchants should not be able to see all the details
    // of an account. Ideally this page should be visible to merchants, too,
    // just with less information.

    $account = id(new PhortuneAccountQuery())
      ->setViewer($user)
      ->withIDs(array($this->accountID))
      ->requireCapabilities(
        array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))
      ->executeOne();

    if (!$account) {
      return new Aphront404Response();
    }

    $title = $account->getName();

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Account'), $request->getRequestURI());

    $header = id(new PHUIHeaderView())
      ->setHeader($title);

    $actions = id(new PhabricatorActionListView())
      ->setUser($user)
      ->setObjectURI($request->getRequestURI())
      ->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Edit Account'))
          ->setIcon('fa-pencil')
          ->setHref('#')
          ->setDisabled(true))
      ->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Edit Members'))
          ->setIcon('fa-users')
          ->setHref('#')
          ->setDisabled(true));

    $crumbs->setActionList($actions);

    $properties = id(new PHUIPropertyListView())
      ->setObject($account)
      ->setUser($user);

    $properties->setActionList($actions);

    $payment_methods = $this->buildPaymentMethodsSection($account);
    $purchase_history = $this->buildPurchaseHistorySection($account);
    $charge_history = $this->buildChargeHistorySection($account);
    $account_history = $this->buildAccountHistorySection($account);

    $object_box = id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->addPropertyList($properties);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $object_box,
        $payment_methods,
        $purchase_history,
        $charge_history,
        $account_history,
      ),
      array(
        'title' => $title,
      ));
  }

  private function buildPaymentMethodsSection(PhortuneAccount $account) {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $account,
      PhabricatorPolicyCapability::CAN_EDIT);

    $id = $account->getID();

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Payment Methods'));

    $list = id(new PHUIObjectItemListView())
      ->setUser($viewer)
      ->setNoDataString(
        pht('No payment methods associated with this account.'));

    $methods = id(new PhortunePaymentMethodQuery())
      ->setViewer($viewer)
      ->withAccountPHIDs(array($account->getPHID()))
      ->execute();

    if ($methods) {
      $this->loadHandles(mpull($methods, 'getAuthorPHID'));
    }

    foreach ($methods as $method) {
      $id = $method->getID();

      $item = new PHUIObjectItemView();
      $item->setHeader($method->getFullDisplayName());

      switch ($method->getStatus()) {
        case PhortunePaymentMethod::STATUS_ACTIVE:
          $item->setBarColor('green');

          $disable_uri = $this->getApplicationURI('card/'.$id.'/disable/');
          $item->addAction(
            id(new PHUIListItemView())
              ->setIcon('fa-times')
              ->setHref($disable_uri)
              ->setDisabled(!$can_edit)
              ->setWorkflow(true));
          break;
        case PhortunePaymentMethod::STATUS_DISABLED:
          $item->setDisabled(true);
          break;
      }

      $provider = $method->buildPaymentProvider();
      $item->addAttribute($provider->getPaymentMethodProviderDescription());

      $edit_uri = $this->getApplicationURI('card/'.$id.'/edit/');

      $item->addAction(
        id(new PHUIListItemView())
          ->setIcon('fa-pencil')
          ->setHref($edit_uri)
          ->setDisabled(!$can_edit)
          ->setWorkflow(!$can_edit));

      $list->addItem($item);
    }

    return id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->appendChild($list);
  }

  private function buildPurchaseHistorySection(PhortuneAccount $account) {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $carts = id(new PhortuneCartQuery())
      ->setViewer($viewer)
      ->withAccountPHIDs(array($account->getPHID()))
      ->needPurchases(true)
      ->withStatuses(
        array(
          PhortuneCart::STATUS_PURCHASING,
          PhortuneCart::STATUS_CHARGED,
          PhortuneCart::STATUS_HOLD,
          PhortuneCart::STATUS_PURCHASED,
        ))
      ->execute();

    $phids = array();
    foreach ($carts as $cart) {
      $phids[] = $cart->getPHID();
      foreach ($cart->getPurchases() as $purchase) {
        $phids[] = $purchase->getPHID();
      }
    }
    $handles = $this->loadViewerHandles($phids);

    $rows = array();
    $rowc = array();
    foreach ($carts as $cart) {
      $cart_link = $handles[$cart->getPHID()]->renderLink();
      $purchases = $cart->getPurchases();

      if (count($purchases) == 1) {
        $purchase_name = $handles[$purchase->getPHID()]->renderLink();
        $purchases = array();
      } else {
        $purchase_name = '';
      }

      $rowc[] = '';
      $rows[] = array(
        $cart->getID(),
        phutil_tag(
          'strong',
          array(),
          $cart_link),
        $purchase_name,
        phutil_tag(
          'strong',
          array(),
          $cart->getTotalPriceAsCurrency()->formatForDisplay()),
        PhortuneCart::getNameForStatus($cart->getStatus()),
        phabricator_datetime($cart->getDateModified(), $viewer),
      );
      foreach ($purchases as $purchase) {
        $id = $purchase->getID();

        $price = $purchase->getTotalPriceAsCurrency()->formatForDisplay();

        $rowc[] = '';
        $rows[] = array(
          '',
          $handles[$purchase->getPHID()]->renderLink(),
          $price,
          '',
          '',
        );
      }
    }

    $table = id(new AphrontTableView($rows))
      ->setRowClasses($rowc)
      ->setHeaders(
        array(
          pht('ID'),
          pht('Order'),
          pht('Purchase'),
          pht('Amount'),
          pht('Status'),
          pht('Updated'),
        ))
      ->setColumnClasses(
        array(
          '',
          '',
          'wide',
          'right',
          '',
          'right',
        ));

    $header = id(new PHUIHeaderView())
      ->setHeader(pht('Order History'));

    return id(new PHUIObjectBoxView())
      ->setHeader($header)
      ->appendChild($table);
  }

  private function buildChargeHistorySection(PhortuneAccount $account) {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    $charges = id(new PhortuneChargeQuery())
      ->setViewer($viewer)
      ->withAccountPHIDs(array($account->getPHID()))
      ->needCarts(true)
      ->execute();

    return $this->buildChargesTable($charges);
  }

  private function buildAccountHistorySection(PhortuneAccount $account) {
    $request = $this->getRequest();
    $user = $request->getUser();

    $xactions = id(new PhortuneAccountTransactionQuery())
      ->setViewer($user)
      ->withObjectPHIDs(array($account->getPHID()))
      ->execute();

    $engine = id(new PhabricatorMarkupEngine())
      ->setViewer($user);

    $xaction_view = id(new PhabricatorApplicationTransactionView())
      ->setUser($user)
      ->setObjectPHID($account->getPHID())
      ->setTransactions($xactions)
      ->setMarkupEngine($engine);

    return $xaction_view;
  }

}
