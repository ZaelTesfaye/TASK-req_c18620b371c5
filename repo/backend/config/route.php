<?php
/**
 * Route Configuration
 */
return [
    'url_route_must'       => true,
    'route_rule_merge'     => true,
    'url_lazy_route'       => false,
    // Without this, a parameter-less rule like `POST /orders` matches
    // `POST /orders/:id/confirm` as a prefix (see RuleItem::checkMatch
    // line 255) and beats the more specific `POST /orders/<id>/confirm`
    // registered later, so every order-lifecycle endpoint 400s through
    // OrderController::create instead of the intended action handler.
    'route_complete_match' => true,
];
