#pragma once
// Deliberately public synthetic keys for host tests ONLY. Never provision them.
#define MESH_THIS_NODE 1
static const uint8_t MESH_GROUP_KEY[32]={0};
static const uint8_t MESH_TEST_KEYS[10][32]={{1},{2},{3},{4},{5},{6},{7},{8},{9},{10}};
static const uint8_t* meshOriginKey(int n){return n>=1&&n<=10?MESH_TEST_KEYS[n-1]:nullptr;}
